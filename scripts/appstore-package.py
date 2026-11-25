#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build/verify the unsigned App Store archive from sources and Vite output."""
import argparse
import gzip
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
APP = "librefinance"
ARCHIVE = ROOT / "build/artifacts" / (APP + ".tar.gz")
RUNTIME = (
    "appinfo", "lib", "templates", "l10n", "img", "schemas", "docs",
    "LICENSE", "LICENSES", "README.md", "SECURITY.md", "composer.json",
    "composer.json.license", "openapi.json", "openapi.json.license",
)
REQUIRED_ASSETS = (
    "js/main.js", "js/main.js.license", "js/admin.js", "js/admin.js.license",
    "css/main.css", "css/main.css.license", "css/admin.css", "css/admin.css.license",
)


def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def regular(relative):
    path = ROOT / relative
    if path.is_symlink() or not path.is_file() or not path.resolve().is_relative_to(ROOT):
        raise ValueError(f"Not a regular file inside the application: {relative}")
    return path


def assets():
    if git("ls-files", "-z", "--", "js", "css"):
        raise ValueError("Generated js/ and css/ must not be tracked")
    for relative in REQUIRED_ASSETS:
        if regular(relative).stat().st_size == 0:
            raise ValueError(f"Empty generated asset: {relative}")
        if subprocess.run(
            ["git", "-C", str(ROOT), "check-ignore", "-q", "--", relative],
            check=False,
        ).returncode:
            raise ValueError(f"Generated asset is not ignored: {relative}")
    # Vite empties js/ for the main build before adding admin and hashed assets.
    # CSS output is exactly the two stylesheets and their retained notices.
    javascript = {
        p.relative_to(ROOT).as_posix()
        for p in (ROOT / "js").rglob("*")
        if p.is_file() or p.is_symlink()
    }
    paths = javascript | {p for p in REQUIRED_ASSETS if p.startswith("css/")}
    for relative in paths:
        regular(relative)
    return paths


def package_files():
    sources = {
        p.decode() for p in git("ls-files", "-z", "--", *RUNTIME).split(b"\0") if p
    }
    paths = sorted(sources | assets())
    for relative in paths:
        regular(relative)
    return paths


def verify(archive, paths):
    expected = {f"{APP}/{p}": p for p in paths}
    with tarfile.open(archive, "r:gz") as package:
        members = package.getmembers()
        # No unexpected files, links, duplicate entries or path traversal.
        if len(members) != len(expected) or set(m.name for m in members) != set(expected):
            raise ValueError("App Store archive differs from the explicit runtime file set")
        for member in members:
            if not member.isfile():
                raise ValueError(f"Unexpected non-regular archive entry: {member.name}")
            stream = package.extractfile(member)
            if stream is None or stream.read() != regular(expected[member.name]).read_bytes():
                raise ValueError(f"Archive content mismatch: {member.name}")
    print(f"Verified {len(expected)} runtime files, including all generated assets")


def build(paths):
    ARCHIVE.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=ARCHIVE.parent, suffix=".tar.gz", delete=False) as output:
        temporary = Path(output.name)
        try:
            with gzip.GzipFile(filename="", fileobj=output, mode="wb", mtime=0) as compressed:
                with tarfile.open(fileobj=compressed, mode="w", format=tarfile.PAX_FORMAT) as package:
                    for relative in paths:
                        path = regular(relative)
                        entry = package.gettarinfo(str(path), arcname=f"{APP}/{relative}")
                        entry.uid = entry.gid = 0
                        entry.uname = entry.gname = ""
                        entry.mtime = 0
                        entry.mode = 0o755 if entry.mode & 0o111 else 0o644
                        with path.open("rb") as content:
                            package.addfile(entry, content)
            output.flush()
            verify(temporary, paths)
            temporary.replace(ARCHIVE)
        finally:
            temporary.unlink(missing_ok=True)
    print(f"Unsigned App Store archive: {ARCHIVE.relative_to(ROOT)}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("verify-assets", "build", "verify"))
    args = parser.parse_args()
    try:
        if args.command == "verify-assets":
            print(f"Verified {len(assets())} generated files outside the Git index")
        elif args.command == "build":
            build(package_files())
        else:
            verify(ARCHIVE, package_files())
    except (OSError, ValueError, subprocess.CalledProcessError, tarfile.TarError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
