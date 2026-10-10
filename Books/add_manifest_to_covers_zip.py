"""Adds the missing covers_manifest.csv to your existing covers.zip, without re-running the big notebook.

The Import Book Covers tool in WordPress needs covers_manifest.csv inside the zip: it lists which
cover belongs to which book (by title and author). Older versions of the notebook did not create it.

Use the SAME workbook you imported into WordPress (so the titles match exactly), for example
wordpress_book_database_import_review_fixed.xlsx.

Save this file in the same folder as covers.zip and your workbook (Site\\Books), set the names below, and run it from anywhere.
Your original covers.zip is not changed: a new covers_with_manifest.zip is created.
Needs:  pip install openpyxl
"""
import csv
import io
import os
import zipfile

from openpyxl import load_workbook

# ===================== SETTINGS =====================
WORKBOOK = "wordpress_book_database_import_review_authors_filled.xlsx"   # the file you imported into WordPress
ZIP_IN   = "covers.zip"
ZIP_OUT  = "covers_with_manifest.zip"
MAX_ZIP_MB = 40    # e.g. 20 to split into several smaller zips (if your WordPress rejects the big one); None = one zip
# ====================================================

# Look for the files in the folder this script is saved in (Site\Books), whichever folder the terminal is in.
try:
    FOLDER = os.path.dirname(os.path.abspath(__file__))
except NameError:                      # running inside a notebook
    FOLDER = os.getcwd()


def main():
    global WORKBOOK, ZIP_IN, ZIP_OUT
    WORKBOOK, ZIP_IN, ZIP_OUT = (os.path.join(FOLDER, n) for n in (WORKBOOK, ZIP_IN, ZIP_OUT))
    for path in (WORKBOOK, ZIP_IN):
        if not os.path.exists(path):
            raise FileNotFoundError("Could not find %r. Put this file beside it, or change the name in SETTINGS." % path)

    wb = load_workbook(WORKBOOK, read_only=True, data_only=True)
    if "Books" not in wb.sheetnames:
        raise RuntimeError("The workbook has no 'Books' sheet.")
    rows = wb["Books"].iter_rows(values_only=True)
    header = [str(h).strip() if h is not None else "" for h in next(rows)]
    for needed in ("BookTitle", "CoverFile"):
        if needed not in header:
            raise RuntimeError("The Books sheet has no '%s' column." % needed)
    col = {h: i for i, h in enumerate(header)}

    with zipfile.ZipFile(ZIP_IN) as zin:
        images = {os.path.basename(n) for n in zin.namelist() if n.lower().endswith(".jpg")}
        had_manifest = "covers_manifest.csv" in zin.namelist()

        entries = []                                   # (file name, title, author, isbn13)
        missing = 0
        for r in rows:
            cover = r[col["CoverFile"]]
            if not cover:
                continue
            name = os.path.basename(str(cover).replace("\\", "/"))
            if name not in images:
                missing += 1
                continue

            def cell(label):
                return "" if label not in col or r[col[label]] is None else str(r[col[label]])
            entries.append((name, cell("BookTitle"), cell("Author"), cell("ISBN13")))
        listed = len(entries)

        # Split into parts when MAX_ZIP_MB is set; otherwise everything goes in one zip.
        limit = MAX_ZIP_MB * 1024 * 1024 if MAX_ZIP_MB else None
        sizes = {i.filename: i.file_size for i in zin.infolist()}
        parts, current, used = [], [], 0
        for e in entries:
            size = sizes.get(e[0], 0) or sizes.get("covers/" + e[0], 0)
            if limit and current and used + size > limit:
                parts.append(current)
                current, used = [], 0
            current.append(e)
            used += size
        if current:
            parts.append(current)
        if not parts:
            raise RuntimeError("No images in the zip match a CoverFile in the workbook. Is WORKBOOK the file you imported?")
        names_in_zip = set(zin.namelist())

        base, ext = os.path.splitext(ZIP_OUT)
        outputs = []
        for n, part in enumerate(parts, 1):
            out_name = ZIP_OUT if len(parts) == 1 else "%s_part%d%s" % (base, n, ext)
            manifest = io.StringIO()
            writer = csv.writer(manifest)
            writer.writerow(["File", "BookTitle", "Author", "ISBN13"])
            with zipfile.ZipFile(out_name, "w", zipfile.ZIP_STORED) as zout:
                for name, title, author, isbn in part:
                    member = name if name in names_in_zip else "covers/" + name
                    zout.writestr(name, zin.read(member))
                    writer.writerow([name, title, author, isbn])
                zout.writestr("covers_manifest.csv", manifest.getvalue())
            outputs.append(out_name)

    print("Images in the zip: %d" % len(images))
    print("Listed in covers_manifest.csv: %d" % listed)
    if missing:
        print("%d CoverFile entries in the workbook have no matching image in the zip (ignored)." % missing)
    if listed < len(images):
        print("%d image(s) in the zip are not listed because the workbook has no CoverFile for them." % (len(images) - listed))
    if had_manifest:
        print("(The zip already had a manifest, which has been replaced.)")
    if len(outputs) == 1:
        print("\nSaved %s\nUpload this one in Tools > Import Book Covers." % outputs[0])
    else:
        print("\nSaved %d zips:" % len(outputs))
        for o in outputs:
            print("  %s (%.1f MB)" % (o, os.path.getsize(o) / 1e6))
        print("Upload them one at a time in Tools > Import Book Covers (each upload carries on from the last).")


if __name__ == "__main__":
    main()
