# `app/templates/rcf/`

`styles.xml` is the **`xl/styles.xml` part of the Roster Change Form workbook
Rodeo Houston sends out**, copied here byte for byte.

It is here rather than reimplemented because the requirement on the generated
form is that it look *exactly* like the one committee officers already fill in
by hand: the same fourteen fonts, the same eleven fills, the same twenty-six
border combinations and — the part that cannot be eyeballed — the same
ninety-three `cellXfs` in the same order, because `Rerm\Forms\RosterChangeForm`
addresses them by index. Rebuilding that by hand would be ninety-three chances
to be one shade off, and a form that is one shade off is a form somebody has to
check.

It is safe to ship, and each of these was verified rather than assumed:

* **It contains no member data.** A style sheet holds fonts, fills, borders and
  number formats. There is no cell content in it at all — the values live in
  `sheet1.xml`, which this application generates.
* **It is self-contained.** Every colour is an explicit `rgb=` or an indexed
  one; nothing refers to `xl/theme/theme1.xml`, so the theme part is not
  shipped and the generated workbook does not need one.
* **It is XML, not a spreadsheet.** `.gitignore` refuses `*.xlsx` and CI fails
  on a tracked spreadsheet — both deliberately, because a real roster is 1,950
  people's home addresses and this repository is public. A style sheet is
  neither a roster nor a workbook, and it is checked by
  `.github/check-no-pii.py` like every other tracked text file.

**Do not edit it to change how the form looks.** The style ids are a contract
with `RosterChangeForm::CHROME` and `::ENTRY_ROW_STYLES`, which were
transcribed from the same workbook's `sheet1.xml`. If Rodeo Houston sends a new
version of the form, replace this file and re-transcribe both tables from the
new `sheet1.xml` in the same commit.
