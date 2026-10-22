"""Playwright E2E checks for the AHSOBC Omeka S Centennial Photo Gallery (/gallery/)."""

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


def test_gallery_loads_seeded_items_and_timeline_scrubber():
    rows = _load_manifest_rows()
    expected_count = len(rows)
    first_row = rows[0]

    with browser_page() as page:
        resp = page.goto("/gallery/")
        assert resp is not None and resp.status == 200
        assert "Centennial Photo Gallery" in page.title()
        assert page.get_by_role(
            "heading", name="Centennial Alumni Photographic Repository (1926–2026)"
        ).is_visible()

        # Verify card count matches the dynamic manifest row count
        cards = page.locator("article.photo-card")
        assert cards.count() == expected_count

        # Verify first manifest item title is rendered
        assert page.get_by_role("link", name=first_row["dcterms:title"]).is_visible()

        # Filter by 1930s decade pill in the 1926-2026 Timeline Scrubber
        page.get_by_role("link", name="1930s").click()
        assert "decade=1930s" in page.url
        assert page.get_by_role("link", name=first_row["dcterms:title"]).is_visible()


def test_gallery_mirador_inspector_and_iiif_manifest_endpoint():
    rows = _load_manifest_rows()
    target_row = rows[0]
    slug = Path(target_row["file_path"]).stem.lower().replace("_", "-")

    # Verify IIIF Presentation API v3 JSON-LD endpoint
    with urllib.request.urlopen(f"{BASE_URL}/gallery/?iiif_manifest={slug}") as resp:
        assert resp.status == 200
        manifest = json.loads(resp.read().decode("utf-8"))
        assert manifest["type"] == "Manifest"
        assert manifest["label"]["en"][0] == target_row["dcterms:title"]

    # Verify Mirador Deep-Zoom Inspector section opens on item click
    with browser_page() as page:
        page.goto(f"/gallery/?item={slug}")
        inspector = page.locator("#mirador-viewer")
        assert inspector.is_visible()
        assert target_row["dcterms:title"] in inspector.inner_text()


def test_gallery_lifespan_view_and_crowdsourced_face_id_submission():
    rows = _load_manifest_rows()
    target_slug = Path(rows[0]["file_path"]).stem.lower().replace("_", "-")

    with browser_page() as page:
        # 1. Inspect Cohort Faceting & Alumni Lifespan profile view
        page.goto("/gallery/?alumnus=dr-kamau-githunguri-1960")
        lifespans = page.locator("#lifespans")
        assert "Dr. Joseph Kamau Githunguri" in lifespans.inner_text()
        assert "School No. #1842" in lifespans.inner_text()

        # 2. Submit a moderated Historic Face Identification (Contribution Type 3)
        face_form = page.get_by_role("form", name="Identify a Classmate in a Group Photo")
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
    test_gallery_loads_seeded_items_and_timeline_scrubber,
    test_gallery_mirador_inspector_and_iiif_manifest_endpoint,
    test_gallery_lifespan_view_and_crowdsourced_face_id_submission,
]

if __name__ == "__main__":
    for t in TESTS:
        t()
        print(f"PASS {t.__name__}")
