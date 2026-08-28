# `app/templates/rcf/`

`styles.xml` and `featurePropertyBag.xml` are two parts of the Roster Change
Form workbook Rodeo Houston sends out, copied here byte for byte.

**They ship together or not at all.** Two of the style sheet's ninety-three
cell formats — 60 and 61, which is exactly the twenty-five ROOKIE cells and
the twenty-five WAIT LIST cells — carry `<xfpb:xfComplement i="0"/>`. That is
an *index into* `featurePropertyBag.xml`, and following it lands on
`CellControl -> Checkbox`: those fifty cells are Excel checkboxes, which is
why the blank form holds a numeric `0` in both columns of every row. It is an
unchecked box, not a leftover.

Ship the style sheet without the bag and Excel resolves the index, finds
nothing, and opens the form with *"Repaired Records: Format from
/xl/styles.xml part (Styles)"* — the checkboxes gone, and the user told their
form was damaged. `Rerm\Forms\FormSheet::create()` refuses to build a package
whose style sheet references a bag it was not given, so this cannot be
half-shipped by accident.

They are here rather than reimplemented because the requirement on the
generated form is that it look *exactly* like the one committee officers
already fill in by hand: the same fourteen fonts, the same eleven fills, the
same twenty-six border combinations and — the part that cannot be eyeballed —
the same ninety-three `cellXfs` in the same order, because
`Rerm\Forms\RosterChangeForm` addresses them by index. Rebuilding that by hand
would be ninety-three chances to be one shade off, and a form that is one shade
off is a form somebody has to check.

It is safe to ship, and each of these was verified rather than assumed:

* **Neither contains member data.** A style sheet holds fonts, fills, borders
  and number formats; the bag holds four property bags describing a checkbox.
  There is no cell content in either — the values live in `sheet1.xml`, which
  this application generates.
* **It is self-contained.** Every colour is an explicit `rgb=` or an indexed
  one; nothing refers to `xl/theme/theme1.xml`, so the theme part is not
  shipped and the generated workbook does not need one.
* **They are XML, not spreadsheets.** `.gitignore` refuses `*.xlsx` and CI
  fails on a tracked spreadsheet — both deliberately, because a real roster is
  1,950 people's home addresses and this repository is public. Neither of these
  is a roster or a workbook, and both are checked by
  `.github/check-no-pii.py` like every other tracked text file.

**Do not edit these to change how the form looks.** The style ids are a
contract with `RosterChangeForm::CHROME_STYLES` and `::ENTRY_ROW_STYLES`, which
were transcribed from the same workbook's `sheet1.xml`. If Rodeo Houston sends
a new version of the form, replace **both** files and re-transcribe both tables
from the new `sheet1.xml` in the same commit — and check whether the new one
still has checkboxes in columns C and H.
