<?php

namespace Appwrite\Alert;

use Appwrite\Template\Template;

/**
 * The email an alert rule sends when it starts or stops firing: a headline
 * saying what crossed which threshold, the value against the threshold, the
 * rule's details and a link to the rule.
 */
readonly class Email
{
    /**
     * @param string $metric what the rule watches, as people read it ("Outbound bandwidth")
     * @param string $place where it is measured ("Demo Project")
     * @param ?int $window seconds a counter is summed over; null for a gauge's latest value
     * @param array<string, string> $details label and value rows under the summary
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
    ) {
    }

    public function subject(): string
    {
        return ($this->status === Status::Firing ? 'Alert firing: ' : 'Alert resolved: ') . $this->rule;
    }

    public function heading(): string
    {
        $crossed = ($this->status === Status::Firing ? 'is ' : 'is no longer ') . $this->comparator->phrase();

        return "{$this->metric} {$crossed} {$this->threshold->format()}";
    }

    /**
     * One sentence for the inbox preview, the webhook body and the email's first paragraph.
     */
    public function summary(): string
    {
        $value = $this->value->format();
        $threshold = $this->threshold->format();
        $over = $this->comparator->phrase();
        $period = $this->window === null ? '' : ' in ' . $this->period();

        return $this->status === Status::Firing
            ? "{$this->metric} on {$this->place} reached {$value}{$period}, {$over} the {$threshold} threshold."
            : "{$this->metric} on {$this->place} is {$value}{$period} and no longer {$over} the {$threshold} threshold.";
    }

    public function render(string $templatePath): string
    {
        $firing = $this->status === Status::Firing;

        $rows = '';
        foreach ($this->details as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding: 10px 16px 10px 0; border-top: 1px solid #e8e9f0; color: #818186; width: 35%; white-space: nowrap;">' . \htmlspecialchars($label) . '</td>'
                . '<td style="padding: 10px 0; border-top: 1px solid #e8e9f0; color: #373b4d; word-break: break-word;">' . \htmlspecialchars($value) . '</td>'
                . '</tr>';
        }

        return Template::fromFile($templatePath)
            ->setParam('{{statusLabel}}', $firing ? 'Firing' : 'Resolved')
            ->setParam('{{statusBackground}}', $firing ? '#fff1f2' : '#ecfdf3')
            ->setParam('{{statusColor}}', $firing ? '#b42318' : '#067647')
            ->setParam('{{statusBorder}}', $firing ? '#fecdca' : '#abefc6')
            ->setParam('{{changedAt}}', $this->changedAt->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, H:i') . ' UTC')
            ->setParam('{{summary}}', $this->summary())
            ->setParam('{{valueLabel}}', $firing ? 'Value' : 'Value now')
            ->setParam('{{value}}', $this->value->format())
            ->setParam('{{valueColor}}', $firing ? '#b42318' : '#373b4d')
            ->setParam('{{valueNote}}', $this->window === null ? 'Latest reading' : 'In ' . $this->period())
            ->setParam('{{threshold}}', $this->comparator->symbol() . ' ' . $this->threshold->format())
            ->setParam('{{thresholdNote}}', $this->window === null ? 'Compared with the latest reading' : 'Summed over ' . $this->duration())
            ->setParam('{{rows}}', $rows, escapeHtml: false)
            ->setParam('{{url}}', $this->url)
            ->setParam('{{reason}}', $this->reason)
            ->render(minify: false);
    }

    /**
     * The window as a span of time ending now: "the last hour", "the last 15 minutes".
     */
    private function period(): string
    {
        [$count, $unit] = $this->span();

        return $count === 1 ? "the last {$unit}" : "the last {$count} {$unit}s";
    }

    /**
     * The window as a length: "1 hour", "15 minutes".
     */
    private function duration(): string
    {
        [$count, $unit] = $this->span();

        return $count === 1 ? "1 {$unit}" : "{$count} {$unit}s";
    }

    /**
     * @return array{int, string}
     */
    private function span(): array
    {
        $seconds = (int) $this->window;
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $length) {
            if ($seconds >= $length && $seconds % $length === 0) {
                return [\intdiv($seconds, $length), $unit];
            }
        }

        return [$seconds, 'second'];
    }
}
