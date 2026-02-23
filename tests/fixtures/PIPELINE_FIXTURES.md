# Pipeline End-to-End Test Fixtures

The end-to-end pipeline test (`tests/Integration/PipelineEndToEndTest.php`) requires the following fixtures. Some already exist in this directory; others must be created manually.

## Binary Dependencies

- **ffmpeg** — used by ScreenshotGenerator to extract frames
- **tesseract** — used by FrameClassifier and BillTextExtractor for OCR

If either is missing the test is skipped automatically.

## Already Available

| File | Source | Notes |
|------|--------|-------|
| `house-floor.mp4` | `bin/fetch_test_fixtures.php` | Short House floor video clip. Run the fetch script if missing. |
| `house-floor-video.html` | Checked into repo | House floor session detail page (Feb 12, 2025 "Regular Session"). Contains Root.clone data with downloadMediaUrls, AgendaTree, Speakers, and ccItems. The ccItems data is used by the scraper to generate WebVTT captions automatically. |

## Must Be Created

### `house-floor-listing.json`

The House listing API response fixture. The existing `house-listing.json` has an Appropriations (committee) entry that doesn't match `house-floor-video.html`. The pipeline test needs a listing that includes a **floor session** entry pointing to the same video represented by `house-floor-video.html`.

**How to create it:**

Fetch the listing API for the week of February 12, 2025:

```bash
curl -s 'https://sg001-harmony.sliq.net/00304/Harmony/en/api/Data/GetListViewData?categoryId=-1&fromDate=2025-02-12T00:00:00&endDate=2025-02-12T23:59:59&searchTime=&searchForward=true&order=0' -o tests/fixtures/house-floor-listing.json
```

The response must contain an entry with `"Id": 20838` (matching the content entity ID in `house-floor-video.html`). If the API returns multiple entries for that week, they can all be kept — the test uses `maxRecords: 1` and processes only the first event with a valid `Id`.

If the API is unavailable, copy `house-listing.json` and replace the Appropriations entry with a floor session entry. The minimum required structure is:

```json
{
  "NextTime": null,
  "PreviousTime": null,
  "Weeks": [
    {
      "WeekStart": "2025-02-10T00:00:00",
      "ContentEntityDatas": [
        {
          "Title": "Regular Session",
          "IconUri": "",
          "EntityStatus": -1,
          "EntityStatusDesc": "Adjourned",
          "Location": "",
          "Description": "February 12, 2025 - Regular Session",
          "ThumbnailUri": "/00304/Harmony/images/video_small.png",
          "ScheduledStart": "2025-02-12T11:46:00",
          "ScheduledEnd": "2025-02-12T13:52:00",
          "HasArchiveStream": true,
          "ActualStart": "2025-02-12T11:46:00",
          "ActualEnd": "2025-02-12T13:52:00",
          "LastModifiedTime": "2025-02-12T14:00:00",
          "CommitteeId": null,
          "VenueId": null,
          "ForeignKey": "20838",
          "Id": 20838,
          "Tag": null
        }
      ]
    }
  ]
}
```

## Fixture Relationships

The fixtures form a coherent set representing one House floor session:

```
house-floor-listing.json  -->  lists the session (scrape stage reads this)
house-floor-video.html    -->  session detail page (scrape stage reads this; also provides captions via ccItems)
house-floor.mp4           -->  the video file (download/screenshot stages use this)
```

All three must represent the same session for the pipeline to flow correctly. The existing `house-floor-video.html` is dated February 12, 2025, so the listing should match that date.
