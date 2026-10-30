<?php

namespace Appwrite\Alert;

use Appwrite\Template\Template;

/**
 * The email a monitor sends when it starts or stops firing: a headline
 * saying what crossed which threshold, where, the value against the threshold,
 * when the next email can come, and links to the rule.
 */
readonly class Email
{
    private const array TONES = [
        'firing' => ['background' => '#fff1f2', 'color' => '#b42318', 'border' => '#fecdca'],
        'resolved' => ['background' => '#ecfdf3', 'color' => '#067647', 'border' => '#abefc6'],
        'warning' => ['background' => '#fffaeb', 'color' => '#93370d', 'border' => '#fedf89'],
    ];

    /**
     * @param string $metric what the rule watches, as people read it ("Outbound bandwidth")
     * @param string $place what the subject names it by ("Storefront")
     * @param ?int $window seconds a counter is summed over; null for a gauge's latest value
     * @param array<string, string> $details label and value rows after the rule's name
     * @param string $location the line under the headline ("Storefront · Acme · Frankfurt")
     * @param ?\DateTimeImmutable $since when the rule started firing, for how long a resolved rule fired
     * @param int $cooldown seconds before the rule can email again while it keeps firing
     * @param string $pauseUrl where the "Pause this monitor" link goes; none when empty
     * @param string $unsubscribeUrl where the "Unsubscribe" link after the reason goes; none when empty
     */
    public function __construct(
        public Status $status,
        public string $rule,
        public string $metric,
        public string $place,
        public Quantity $value,
        public Comparator $comparator,
        public Quantity $threshold,
        public ?int $window,
        public \DateTimeImmutable $changedAt,
        public array $details,
        public string $url,
        public string $reason,
        public Wording $wording = new Wording(),
        public string $location = '',
        public ?\DateTimeImmutable $since = null,
        public int $cooldown = 0,
        public string $pauseUrl = '',
        public string $unsubscribeUrl = '',
    ) {
    }

    public function subject(): string
    {
        return $this->wording->subject
            ?? ($this->firing() ? '[Firing] ' : '[Resolved] ') . $this->rule . ($this->place === '' ? '' : ' · ' . $this->place);
    }

    public function heading(): string
    {
        if ($this->wording->heading !== null) {
            return $this->wording->heading;
        }

        $crossed = ($this->firing() ? 'is ' : 'is no longer ') . $this->comparator->phrase();

        return "{$this->metric} {$crossed} {$this->threshold->format()}";
    }

    /**
     * One sentence for the inbox preview and the webhook body.
     */
    public function summary(): string
    {
        if ($this->wording->summary !== null) {
            return $this->wording->summary;
        }

        $value = $this->value->format();
        $threshold = $this->threshold->format();
        $over = $this->comparator->phrase();
        $period = $this->window === null ? '' : ' in ' . $this->period();

        if (!$this->firing()) {
            return "{$this->metric} is back to {$value}{$period}, no longer {$over} the {$threshold} threshold.";
        }

        $ratio = $this->ratio();

        return "{$this->metric} reached {$value}{$period}, " . ($ratio === null ? "{$over} the {$threshold} threshold." : "{$ratio} the {$threshold} threshold.");
    }

    public function render(string $templatePath): string
    {
        $tone = self::TONES[$this->wording->tone ?? ($this->firing() ? 'firing' : 'resolved')] ?? self::TONES['firing'];
        $value = $this->wording->value ?? $this->valueFigure();
        $threshold = $this->wording->threshold ?? new Figure(
            'Threshold',
            $this->comparator->symbol() . ' ' . $this->threshold->format(),
            $this->window === null ? 'Compared with the latest reading' : 'Summed over ' . $this->duration(),
        );

        $rows = ['Monitor' => $this->rule, ...$this->details];
        if ($this->firing() && $this->wording->status === null) {
            $rows['Next email'] = $this->cooldown > 0
                ? 'On recovery, or in ' . self::length($this->cooldown) . ' if still firing'
                : 'On recovery';
        }

        return Template::fromFile($templatePath)
            ->setParam('{{location}}', $this->location === '' ? '' : '<p style="margin: 4px 0 0; color: #6c6c75; font-size: 14px; line-height: 20px;">' . \htmlspecialchars($this->location) . '</p>', escapeHtml: false)
            ->setParam('{{statusLabel}}', $this->wording->status ?? ($this->firing() ? 'Firing' : 'Resolved'))
            ->setParam('{{statusBackground}}', $tone['background'])
            ->setParam('{{statusColor}}', $tone['color'])
            ->setParam('{{statusBorder}}', $tone['border'])
            ->setParam('{{changedAt}}', $this->changedAt->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, H:i') . ' UTC')
            ->setParam('{{callout}}', $this->callout(), escapeHtml: false)
            ->setParam('{{valueLabel}}', $value->label)
            ->setParam('{{value}}', $value->value)
            ->setParam('{{valueColor}}', ($this->firing() || $this->wording->tone !== null) ? $tone['color'] : '#19191c')
            ->setParam('{{valueNote}}', $value->note)
            ->setParam('{{thresholdLabel}}', $threshold->label)
            ->setParam('{{threshold}}', $threshold->value)
            ->setParam('{{thresholdNote}}', $threshold->note)
            ->setParam('{{meter}}', $this->progress($tone['color']), escapeHtml: false)
            ->setParam('{{rows}}', $this->rows($rows), escapeHtml: false)
            ->setParam('{{url}}', $this->url)
            ->setParam('{{action}}', $this->wording->action ?? 'View alert')
            ->setParam('{{link}}', $this->link(), escapeHtml: false)
            ->setParam('{{reason}}', $this->reason)
            ->setParam('{{unsubscribe}}', $this->unsubscribe(), escapeHtml: false)
            ->render(minify: false);
    }

    private function firing(): bool
    {
        return $this->status === Status::Firing;
    }

    /**
     * How many times over the threshold the value is ("110×"), when that says
     * more than "above": an upper threshold crossed at least twice over.
     */
    private function ratio(): ?string
    {
        $threshold = $this->threshold->amount;
        if (!\in_array($this->comparator, [Comparator::GreaterThan, Comparator::GreaterThanEqual], true) || $threshold <= 0) {
            return null;
        }

        $times = $this->value->amount / $threshold;
        if ($times < 2) {
            return null;
        }

        return ($times < 10 ? \rtrim(\rtrim(\number_format($times, 1), '0'), '.') : \number_format($times, 0, '.', ',')) . '×';
    }

    private function valueFigure(): Figure
    {
        if (!$this->firing() && $this->since !== null) {
            $start = $this->since->setTimezone(new \DateTimeZone('UTC'));
            $end = $this->changedAt->setTimezone(new \DateTimeZone('UTC'));

            return new Figure(
                'Firing for',
                self::length(\max(60, $end->getTimestamp() - $start->getTimestamp()), short: true),
                $start->format('H:i') . ' to ' . $end->format('H:i') . ' UTC',
            );
        }

        $label = $this->window === null ? 'Latest reading' : \ucfirst('in ' . $this->period());
        if (!$this->firing()) {
            return new Figure($label, $this->value->format(), 'Back within the threshold');
        }

        $ratio = $this->ratio();

        return new Figure($label, $this->value->format(), $ratio === null ? \ucfirst($this->comparator->phrase()) . ' the threshold' : "{$ratio} the threshold");
    }

    private function callout(): string
    {
        if ($this->wording->callout === null) {
            return '';
        }

        return '<p style="margin: 20px 0 0; padding: 10px 12px; border: 1px solid #fedf89; border-radius: 10px; background-color: #fffaeb; color: #4a2a06; font-size: 14px; line-height: 20px;">'
            . \htmlspecialchars($this->wording->callout) . '</p>';
    }

    private function progress(string $color): string
    {
        $meter = $this->wording->progress;
        if ($meter === null) {
            return '';
        }

        $percent = (int) \round(\max(0, \min(100, $meter->percent)));
        $fill = $percent === 0 ? '' : '<td style="width: ' . $percent . '%; height: 8px; background-color: ' . $color . '; border-radius: 99px; font-size: 0; line-height: 0;">&nbsp;</td>';
        $rest = $percent === 100 ? '' : '<td style="height: 8px; font-size: 0; line-height: 0;">&nbsp;</td>';

        return '<tr><td colspan="2" style="padding: 12px 20px 14px; border-top: 1px solid #e8e9f0;">'
            . '<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="width: 100%; background-color: #ececf1; border-radius: 99px;"><tr>' . $fill . $rest . '</tr></table>'
            . '<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="width: 100%; margin-top: 6px; color: #6c6c75; font-size: 12px; line-height: 16px;"><tr>'
            . '<td style="text-align: left;">' . \htmlspecialchars($meter->start) . '</td>'
            . '<td style="text-align: center;">' . \htmlspecialchars($meter->note) . '</td>'
            . '<td style="text-align: right;">' . \htmlspecialchars($meter->end) . '</td>'
            . '</tr></table></td></tr>';
    }

    /**
     * @param array<string, string> $rows
     */
    private function rows(array $rows): string
    {
        $html = '';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<td style="padding: 10px 16px 10px 0; border-top: 1px solid #e8e9f0; color: #6c6c75; width: 34%; white-space: nowrap;">' . \htmlspecialchars($label) . '</td>'
                . '<td style="padding: 10px 0; border-top: 1px solid #e8e9f0; color: #19191c; word-break: break-word;">' . \htmlspecialchars($value) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function link(): string
    {
        [$label, $url] = $this->wording->linkUrl !== null
            ? [(string) $this->wording->link, $this->wording->linkUrl]
            : ['Pause this monitor', $this->firing() ? $this->pauseUrl : ''];

        if ($url === '' || $label === '') {
            return '';
        }

        return '<td style="padding-left: 16px; vertical-align: middle;"><a rel="noopener" target="_blank" href="' . \htmlspecialchars($url) . '" style="color: #19191c; font-size: 14px; text-decoration: underline;">' . \htmlspecialchars($label) . '</a></td>';
    }

    /**
     * A quiet link after the reason, to the page where the recipient stops these emails.
     */
    private function unsubscribe(): string
    {
        if ($this->unsubscribeUrl === '') {
            return '';
        }

        return ' <a rel="noopener" target="_blank" href="' . \htmlspecialchars($this->unsubscribeUrl) . '" style="color: #6c6c75; text-decoration: underline; white-space: nowrap;">Unsubscribe</a>';
    }

    /**
     * The window as a span of time ending now: "the last hour", "the last 15 minutes".
     */
    private function period(): string
    {
        [$count, $unit] = self::span((int) $this->window);

        return $count === 1 ? "the last {$unit}" : "the last {$count} {$unit}s";
    }

    /**
     * The window as a length: "1 hour", "15 minutes".
     */
    private function duration(): string
    {
        return self::length((int) $this->window);
    }

    /**
     * A number of seconds as people say it: "1 hour", "15 minutes", or with
     * `short` the largest two units, "2 h 14 m".
     */
    private static function length(int $seconds, bool $short = false): string
    {
        if ($short) {
            $parts = [];
            foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $length) {
                if ($seconds >= $length) {
                    $parts[] = \intdiv($seconds, $length) . ' ' . $unit;
                    $seconds %= $length;
                }
            }

            return \implode(' ', \array_slice($parts, 0, 2)) ?: '1 m';
        }

        [$count, $unit] = self::span($seconds);

        return $count === 1 ? "1 {$unit}" : "{$count} {$unit}s";
    }

    /**
     * @return array{int, string}
     */
    private static function span(int $seconds): array
    {
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $length) {
            if ($seconds >= $length && $seconds % $length === 0) {
                return [\intdiv($seconds, $length), $unit];
            }
        }

        return [$seconds, 'second'];
    }
}
