#!/bin/sh
# Inside the image: every case on both implementations. run.sh <corpus dir> <results dir>
# Valid files get every operation; broken ones a thumbnail. A case gets 20 seconds.
corpus=$1; results=$2
mkdir -p /tmp/ini && cp /usr/local/etc/php/conf.d/*.ini /tmp/ini/ && rm -f /tmp/ini/utopia.ini
for implementation in imagick extension; do
  out="$results/$implementation"; mkdir -p "$out/files"; : > "$out/cases.jsonl"
  # Cloud's image keeps the extension's ini outside conf.d (the leading colon adds it to the
  # default); the image built here with UTOPIA_EXTENSION=true has it in conf.d.
  if [ "$implementation" = imagick ]; then scan=/tmp/ini
  elif [ -d /usr/local/etc/php/utopia.d ]; then scan=":/usr/local/etc/php/utopia.d"
  else scan=/usr/local/etc/php/conf.d; fi
  run() {
    PHP_INI_SCAN_DIR=$scan timeout -s KILL 20 php /parity/case.php "$1" "$2" "$out/files" > /tmp/case.out 2>/dev/null
    code=$?
    line=$(tail -n 1 /tmp/case.out)
    case "$line" in
      '{'*) echo "$line" ;;
      *) printf '{"file":"%s","operation":"%s","status":"%s","exit":%d}\n' "$(basename "$1")" "$2" "$([ $code -ge 124 ] && echo killed || echo crashed)" "$code" ;;
    esac >> "$out/cases.jsonl"
  }
  for file in "$corpus"/valid/*; do
    for operation in thumb-jpg gravity-webp height-png styled-png focal-avif same-gif unset-jpg; do run "$file" "$operation"; done
  done
  for file in "$corpus"/broken/*; do run "$file" thumb-jpg; done
  echo "$implementation: $(wc -l < "$out/cases.jsonl") cases"
done
