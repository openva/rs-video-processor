# Chyron OCR Configuration

The video processor extracts legislator names and bill numbers from on-screen chyrons (text overlays) using OCR. Different video types and eras use different chyron positions, so the system supports flexible crop region configuration.

## Configuration File

Chyron crop regions are defined in **`config/chyron_regions.yaml`**.

### Structure

The configuration file has two main sections:

- **`bill_detection`** - Crop regions for extracting bill numbers (HB 1234, SB 5678, etc.)
- **`speaker_detection`** - Crop regions for extracting legislator names

Each section can define multiple **eras** based on when the video was recorded, allowing different crop coordinates for different time periods.

### Example Configuration

```yaml
bill_detection:
  # Default era (2020+) - current chyron positions
  default:
    min_date: "2020-01-01"
    regions:
      senate_floor: [0.75, 0.11, 0.14, 0.05]
      senate_committee: [0.75, 0.11, 0.14, 0.06]
      house_floor: [0.74, 0.11, 0.15, 0.08]
      house_committee: [0.74, 0.11, 0.15, 0.08]

  # Era 2016-2019 - older chyron positions
  era_2016:
    min_date: "2016-01-01"
    regions:
      senate_floor: [0.70, 0.10, 0.20, 0.06]
      # ... different coordinates for 2016-2019 videos

speaker_detection:
  default:
    min_date: "2020-01-01"
    regions:
      house_floor: [0.3, 0.77, 0.55, 0.12]
      house_committee: [0, 0.05, 0.15, 0.1]
      senate_floor: [0.14, 0.82, 1.0, 0.08]
      senate_committee: [0.14, 0.82, 0.86, 0.08]
```

### Coordinate Format

Each region is defined as: **`[x_percent, y_percent, width_percent, height_percent]`**

- **x_percent**: Horizontal position (0.0 = left edge, 1.0 = right edge)
- **y_percent**: Vertical position (0.0 = top edge, 1.0 = bottom edge)
- **width_percent**: Width of crop region as percentage of frame width
- **height_percent**: Height of crop region as percentage of frame height

For example, `[0.75, 0.11, 0.14, 0.05]` means:
- Start at 75% from the left edge
- Start at 11% from the top edge
- Crop 14% of the frame width
- Crop 5% of the frame height

### Supported Video Types

The system recognizes four video type combinations:

1. **`senate_floor`** - Senate floor sessions
2. **`senate_committee`** - Senate committee/subcommittee meetings
3. **`house_floor`** - House floor sessions
4. **`house_committee`** - House committee/subcommittee meetings

## Era Selection

When processing a video, the system automatically selects the appropriate era based on the video's date:

1. All eras with `min_date <= video_date` are considered
2. The era with the **latest** `min_date` is selected
3. If no eras match, falls back to the `default` era

**Example:**
- Video date: `2018-03-15`
- Available eras: `default` (2020-01-01), `era_2016` (2016-01-01), `era_2006` (2006-01-01)
- Selected era: `era_2016` (latest era that started before 2018-03-15)

## Date Filtering

As of the current configuration, OCR chyron extraction only processes videos from **2020-01-01 onward**. Videos before this date are skipped because:

- Chyron positions were different in earlier video formats
- OCR accuracy is poor on older video quality
- The cost of processing with low success rates isn't justified

This filter is enforced in:
- `src/Analysis/Bills/BillDetectionJobQueue.php`
- `src/Analysis/Speakers/SpeakerJobQueue.php`

To process older videos, you would need to:
1. Configure appropriate eras in `config/chyron_regions.yaml`
2. Update the date filters in both job queue files

## Adjusting Crop Regions

To fine-tune OCR accuracy for a specific video type:

1. **Download sample screenshots** from `video.richmondsunlight.com`:
   ```
   https://video.richmondsunlight.com/house/floor/20260203/00000123.jpg
   ```

2. **Open in image editor** and measure the chyron position:
   - Note the pixel coordinates of the text overlay
   - Convert to percentages of frame dimensions
   - For a 1920×1080 frame, x=1440px → x_percent=0.75

3. **Update `config/chyron_regions.yaml`**:
   ```yaml
   house_floor: [0.75, 0.11, 0.14, 0.05]  # Updated coordinates
   ```

4. **Test with a single file**:
   ```bash
   php bin/detect_bills.php --file-id=14913
   php bin/detect_speakers.php --file-id=14913
   ```

5. **Review extracted text** in the `video_index` table:
   ```sql
   SELECT screenshot, raw_text, type, resolved
   FROM video_index
   WHERE file_id = 14913
   ORDER BY screenshot;
   ```

## Adding New Eras

To support older video formats with different chyron positions:

1. **Identify the date range** when the format was used
2. **Measure crop coordinates** from sample screenshots
3. **Add a new era** to `config/chyron_regions.yaml`:

```yaml
bill_detection:
  # ... existing eras ...

  era_2012:
    min_date: "2012-01-01"
    regions:
      senate_floor: [0.70, 0.08, 0.18, 0.06]
      senate_committee: [0.70, 0.08, 0.18, 0.06]
      house_floor: [0.72, 0.09, 0.16, 0.07]
      house_committee: [0.72, 0.09, 0.16, 0.07]
```

4. **Update the date filter** in job queues to process older videos:
   ```php
   // In BillDetectionJobQueue.php and SpeakerJobQueue.php
   AND f.date >= '2012-01-01'  // Changed from 2020-01-01
   ```

5. **Test thoroughly** before processing large batches

## Technical Implementation

The crop configuration system consists of:

- **`config/chyron_regions.yaml`** - YAML configuration file
- **`src/Analysis/ChyronRegionConfig.php`** - Loads and parses YAML, selects appropriate era
- **`src/Analysis/Bills/ChamberConfig.php`** - Provides bill detection crops
- **`src/Analysis/Speakers/SpeakerChamberConfig.php`** - Provides speaker detection crops
- **`src/Analysis/Bills/CropConfig.php`** - Data class for crop coordinates

Configuration is loaded once when processing begins and cached for performance.

## Troubleshooting

### No OCR Results

If OCR isn't extracting any text:

1. **Check crop coordinates** - Verify they point to the actual chyron location
2. **Download a screenshot** and visually confirm the chyron is within the crop area
3. **Check video type detection** - Ensure videos are classified correctly (floor vs. committee)
4. **Verify date filtering** - Videos before 2020-01-01 are skipped by default

### Incorrect Extractions

If OCR is extracting wrong text:

1. **Reduce crop area** - Smaller crops reduce noise from other on-screen elements
2. **Adjust vertical position** - Ensure crop doesn't overlap with other text
3. **Check for video format changes** - If extractions suddenly fail, the broadcaster may have moved chyrons

### Committee Videos Not Processing

The `house_committee` configuration may need adjustment:
- Current placeholder: `[0.00, 0.00, 0.2, 0.08]`
- This needs to be configured based on actual committee video screenshots
- Committee videos have different layouts than floor sessions

## Related Documentation

- **[Raw Text Resolution](raw-text-resolution.md)** - How extracted OCR text is matched to database records
- **[Database Schema](../../sql/README.md)** - `video_index` table structure for OCR results
