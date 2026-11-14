"""Build the WHMCS module zip: modules/gateways/... plus README and TESTING.
   python3 build.py  ->  dist/arionpay-for-whmcs-<version>.zip  (unzip in the WHMCS main folder)"""
import os, re, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
version = re.search(r"ARIONPAY_WHMCS_VERSION = '([^']+)'", (HERE / "modules/gateways/arionpay/lib.php").read_text()).group(1)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / f"arionpay-for-whmcs-{version}.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(HERE / "modules"):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, str(p.relative_to(HERE)))
    z.write(HERE / "README.md", "ARIONPAY-README.md")
    z.write(HERE / "TESTING.md", "ARIONPAY-TESTING.md")
print(out, os.path.getsize(out), "bytes")
