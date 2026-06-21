#!/usr/bin/env bash
# Regenerate the "Plugins" table in README.md from each plugin's discuz_plugin_<id>.xml.
# The table is written between the <!-- PLUGINS:START --> / <!-- PLUGINS:END --> markers.
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="Discuz_X3.5_SC_UTF8/upload/source/plugin"
README="README.md"
START="<!-- PLUGINS:START -->"
END="<!-- PLUGINS:END -->"

# Discuz's bundled/built-in plugins — not listed (only your own plugins appear).
BUILTINS="mobile myrepeats qqconnect witframe_api botaccess"

cdata() { grep -o "<item id=\"$2\"><!\[CDATA\[[^]]*" "$1" 2>/dev/null | head -1 | sed 's/.*CDATA\[//'; }

rows=""
for d in "$SRC"/*/; do
  id="$(basename "$d")"
  case " $BUILTINS " in *" $id "*) continue ;; esac
  xml="${d}discuz_plugin_${id}.xml"
  [ -f "$xml" ] || continue
  name="$(cdata "$xml" name)";        [ -z "$name" ] && name="$id"
  desc="$(cdata "$xml" description)"
  rel="${SRC}/${id}/"
  label="source/plugin/${id}/"
  doc="docs/plugins/${id}.md"
  doccell="—"; [ -f "$doc" ] && doccell="[📖 guide](${doc})"
  rows+="| \`${id}\` | ${name} | ${doccell} | ${desc} | [\`${label}\`](${rel}) |"$'\n'
done
[ -z "$rows" ] && rows="| _(none yet)_ |  |  |  |  |"$'\n'

tmp="$(mktemp)"
{
  echo "$START"
  echo "| ID | Name | Docs | Description | Path |"
  echo "| --- | --- | --- | --- | --- |"
  printf '%s' "$rows"
  echo "$END"
} > "$tmp"

if [ -f "$README" ] && grep -qF "$START" "$README"; then
  awk -v s="$START" -v e="$END" -v f="$tmp" '
    index($0,s){ while((getline line < f)>0) print line; skip=1; next }
    index($0,e){ skip=0; next }
    skip!=1 { print }
  ' "$README" > "$README.new" && mv "$README.new" "$README"
else
  { echo; echo "## Plugins"; echo; cat "$tmp"; } >> "$README"
fi
rm -f "$tmp"
echo "Updated $README plugins table."
