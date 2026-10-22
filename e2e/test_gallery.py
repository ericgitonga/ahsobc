"""Playwright E2E checks for the AHSOBC Flickr-style Headless Omeka S Photo Gallery (/gallery/)."""

import csv
import json
import urllib.request
from pathlib import Path

from _common import BASE_URL, browser_page

REPO_ROOT = Path(__file__).resolve().parent.parent
MANIFEST_CSV = REPO_ROOT / "omeka" / "staging" / "sample_manifest.csv"


def _load_manifest_rows():
    with MANIFEST_CSV.open("r", encoding="utf-8") as fh:
        return list(csv.DictReader(fh))


def test_gallery_photostream_and_albums_tabs():
    rows = _load_manifest_rows()
    expected_count = len(rows)
    first_row = rows[0]

    with browser_page() as page:
        resp = page.goto("/gallery/")
        assert resp is not None and resp.status == 200
        assert "Centennial Photo Gallery" in page.title()

        # Verify Photostream card count matches manifest row count
        cards = page.locator("article.photo-card")
        assert cards.count() == expected_count
        assert page.get_by_role("link", name=first_row["dcterms:title"]).is_visible()

        # Switch to Flickr Albums tab and verify Occasion & 8-House Albums render
        page.locator("#tab-btn-albums").click()
        albums_panel = page.locator("#view-panel-albums")
        assert albums_panel.is_visible()
        assert "Livingstone House" in albums_panel.inner_text()
        assert "Sellwood House" in albums_panel.inner_text()

        # Filter by 1930s decade pill
        page.get_by_role("link", name="1930s").first.click()
        assert "decade=1930s" in page.url
        assert page.get_by_role("link", name=first_row["dcterms:title"]).is_visible()


def test_gallery_lightbox_and_inline_metadata_editing():
    rows = _load_manifest_rows()
    target_row = rows[0]
    slug = Path(target_row["file_path"]).stem.lower().replace("_", "-")

    # Verify IIIF Presentation API v3 JSON-LD endpoint
    with urllib.request.urlopen(f"{BASE_URL}/gallery/?iiif_manifest={slug}") as resp:
        assert resp.status == 200
        manifest = json.loads(resp.read().decode("utf-8"))
        assert manifest["type"] == "Manifest"

    # Verify Headless Omeka S Search & Albums JSON API (/api/gallery.php)
    with urllib.request.urlopen(f"{BASE_URL}/api/gallery.php?house=Francis") as resp:
        assert resp.status == 200
        api_data = json.loads(resp.read().decode("utf-8"))
        assert api_data["status"] == "ok"
        assert api_data["houses"] == [
            "Livingstone",
            "Aggrey",
            "Wilberforce",
            "Grieve",
            "Arthur",
            "Francis",
            "Smith",
            "Sellwood",
        ]
        assert len(api_data["albums"]) >= 12
        assert all(it["house"] == "Francis" for it in api_data["items"])

    # Open Lightbox, inspect per-image metadata, and test inline metadata editing
    with browser_page() as page:
        page.goto("/gallery/")
        page.locator(".photo-card-visual").first.click()
        lightbox = page.locator("#ahsobc-lightbox")
        assert lightbox.is_visible()
        assert target_row["dcterms:title"] in lightbox.inner_text()

        # Click "✎ Edit Metadata", update spatial venue, save, and restore original
        page.locator("#lb-edit-toggle-btn").click()
        edit_form = page.locator("#lb-edit-form")
        assert edit_form.is_visible()
        page.locator("#edit-spatial").fill("The Backfield (Updated), Alliance High School, Kikuyu")
        page.locator("#lb-save-metadata-btn").click()

        save_banner = page.locator("#lb-save-status")
        save_banner.wait_for(state="visible", timeout=5000)
        assert "Metadata saved" in save_banner.inner_text()
        assert "The Backfield (Updated)" in page.locator("#lb-meta-dl").inner_text()

        # Restore original venue
        page.locator("#lb-edit-toggle-btn").click()
        page.locator("#edit-spatial").fill(target_row["dcterms:spatial"])
        page.locator("#lb-save-metadata-btn").click()
        page.locator("#lb-close-btn").click()
        assert lightbox.is_hidden()


def test_gallery_people_view_and_submit_modal():
    rows = _load_manifest_rows()
    target_slug = Path(rows[0]["file_path"]).stem.lower().replace("_", "-")

    with browser_page() as page:
        # 1. Inspect People / Lifespans view
        page.goto("/gallery/?alumnus=dr-kamau-githunguri-1960")
        lifespans = page.locator("#lifespans")
        assert lifespans.is_visible()
        assert "Dr. Joseph Kamau Githunguri" in lifespans.inner_text()
        assert "School No. #1842" in lifespans.inner_text()

        # 2. Open "+ Submit Photo" modal and switch to "Tag a Classmate in a Photo"
        page.locator("#open-upload-modal-btn").click()
        modal = page.locator("#upload-modal")
        assert modal.is_visible()
        page.locator("#modal-tab-face").click()

        face_form = page.get_by_role("form", name="Identify a Classmate in a Group Photo")
        assert face_form.is_visible()
        face_form.locator("#face-target").select_option(target_slug)
        face_form.locator("#face-row").fill("2")
        face_form.locator("#face-pos").fill("3")
        face_form.locator("#face-fullname").fill("Mr. James S. Smith")
        face_form.locator("#face-sno").fill("1")
        face_form.locator("#face-cohort").fill("1926")
        face_form.locator("#face-house").fill("Smith")
        face_form.locator("#face-subname").fill("E2E Archivist Tester")
        face_form.locator("#face-subemail").fill("archivist@ahsobc.or.ke")
        face_form.locator("input[name='dpa_consent']").check()
        face_form.get_by_role("button", name="Submit Face Identification").click()

        status_banner = page.get_by_role("status")
        assert status_banner.is_visible()
        assert "E2E Archivist Tester" in status_banner.inner_text()


TESTS = [
    test_gallery_photostream_and_albums_tabs,
    test_gallery_lightbox_and_inline_metadata_editing,
    test_gallery_people_view_and_submit_modal,
]

if __name__ == "__main__":
    for t in TESTS:
        t()
        print(f"PASS {t.__name__}")
