# Raw Text Resolution

The raw text resolution system resolves OCR-extracted text in the `video_index` table to their corresponding database references (`linked_id`). This is Phase 7 of the video processing pipeline.

## Overview

After bill detection and speaker detection populate `video_index` with raw OCR text, the resolution system:

- **Legislators**: Fuzzy matches names to `people.id` using multiple algorithms
- **Bills**: Strictly validates bill numbers against `bills.id` with agenda verification

This system handles OCR errors intelligently while maintaining high accuracy to prevent incorrect matches.

## Features

### Intelligent Name Matching
- Multiple fuzzy matching algorithms (Levenshtein, Jaro-Winkler, Token Set Ratio)
- OCR error correction (0↔O, 1↔l, 5↔S, 8↔B, 6↔G)
- Removes titles, parties, and districts from raw text
- Handles name format variations ("Bob Smith" vs "Smith, Bob")
- Phonetic matching using Soundex

### Conservative Bill Matching
- Parses all bill formats (HB, SB, HJR, SJR, HR, SR)
- Validates against meeting agenda to prevent false matches
- Conservative OCR error handling (single-character substitutions only)
- Higher confidence threshold (90%) to avoid wrong bill matches

### Temporal Context Analysis
- Analyzes surrounding screenshots (±5-10 seconds)
- Uses majority vote for consensus matching
- Corrects single OCR errors in sequences
- Validates against meeting speaker lists

## Installation

The system is already installed. Required components:

```
src/Resolution/
├── RawTextResolver.php           # Main orchestrator
├── LegislatorResolver.php        # Legislator matching
├── BillResolver.php              # Bill matching
├── ContextAnalyzer.php           # Temporal clustering
└── FuzzyMatcher/
    ├── SimilarityCalculator.php  # String similarity algorithms
    ├── NameMatcher.php           # Name extraction and matching
    └── BillNumberMatcher.php     # Bill number parsing

bin/resolve_raw_text.php          # CLI interface
```

## Usage

### Basic Usage

Process all unresolved entries:
```bash
php bin/resolve_raw_text.php
```

Process a specific file:
```bash
php bin/resolve_raw_text.php --file-id=12345
```

### Preview Mode (Dry Run)

See what would be resolved without updating the database:
```bash
php bin/resolve_raw_text.php --file-id=12345 --dry-run
```

### Type Filtering

Only resolve legislators:
```bash
php bin/resolve_raw_text.php --type=legislator
```

Only resolve bills:
```bash
php bin/resolve_raw_text.php --type=bill
```

### Force Re-Resolution

Re-resolve entries that already have `linked_id`:
```bash
php bin/resolve_raw_text.php --file-id=12345 --force
```

### Verbose Output

Show detailed matching information:
```bash
php bin/resolve_raw_text.php --verbose
```

### JSON Output

Get results as JSON:
```bash
php bin/resolve_raw_text.php --json > results.json
```

### Batch Processing

Process only first N files:
```bash
php bin/resolve_raw_text.php --limit=10
```

## Command Reference

```bash
php bin/resolve_raw_text.php [options]

Options:
  --file-id=<id>    Process specific file ID
  --dry-run         Preview without updating database
  --force           Re-resolve already matched entries
  --type=<type>     Only process 'legislator' or 'bill'
  --limit=<n>       Limit number of files (batch processing)
  --verbose         Show detailed progress
  --json            Output as JSON
  --help            Show help message
```

## Architecture

### Matching Pipeline

```
Raw Text → Extract/Parse → Find Candidates → Score → Apply Context → Update DB
```

### Legislator Resolution Flow

1. **Extract**: Remove titles, parties, districts from raw text
2. **Query**: Load all legislators for session from `people` + `terms` tables
3. **Score**: Calculate match scores using fuzzy algorithms
4. **Context Boost**: Apply bonuses for temporal clustering and speaker lists
5. **Validate**: Require 75%+ confidence to match
6. **Update**: Set `video_index.linked_id` to `people.id`

### Bill Resolution Flow

1. **Parse**: Extract bill number, chamber, type from raw text
2. **Query**: Load bills for session+chamber from `bills` table
3. **Validate Agenda**: Check if bill appears in meeting agenda (critical!)
4. **Context**: Check adjacent frames for same bill
5. **OCR Variations**: Try conservative variations only if bill in agenda
6. **Strict Threshold**: Require 90%+ confidence (wrong bill worse than no match)
7. **Update**: Set `video_index.linked_id` to `bills.id`

### Key Design Decisions

**Conservative for Bills, Aggressive for Legislators:**
- Bills use 90% threshold (false match is catastrophic)
- Legislators use 75% threshold (false match is recoverable)

**Temporal Context as Safety Net:**
- Single OCR errors in sequences corrected by surrounding frames
- Consensus matching when direct matching fails

**Session-Based Caching:**
- Legislators cached per session (avoid repeated queries)
- Bills cached per session+chamber
- Significant performance improvement

## Database Schema

### Required Tables

- `video_index` - Entries with `raw_text`, `type`, and `linked_id`
- `files` - Video metadata including `session_id` and `video_index_cache`
- `people` - Legislators with `name` and `name_formal`
- `terms` - Legislator terms linking to sessions
- `bills` - Bill numbers for each session
- `sessions` - Session information

### Recommended Indexes

```sql
CREATE INDEX IF NOT EXISTS idx_video_index_file_type
    ON video_index(file_id, type);

CREATE INDEX IF NOT EXISTS idx_video_index_linked_null
    ON video_index(type, linked_id);

CREATE INDEX IF NOT EXISTS idx_bills_session_number
    ON bills(session_id, number);

CREATE INDEX IF NOT EXISTS idx_people_name
    ON people(name);
```

## Configuration

### Confidence Thresholds

Default thresholds can be adjusted by modifying the resolver classes:

**Legislators** (in `LegislatorResolver.php`):
```php
$result = $resolver->resolve($rawText, $context, 75.0); // 75% confidence
```

**Bills** (in `BillResolver.php`):
```php
$result = $resolver->resolve($rawText, $context, 90.0); // 90% confidence
```

### Temporal Window

Adjust the context window (±N seconds) in resolvers:

```php
// LegislatorResolver.php
$temporalContext = $this->contextAnalyzer->getTemporalContext(
    $context['file_id'],
    $context['screenshot'],
    5 // ±5 seconds
);

// BillResolver.php
$temporalContext = $this->contextAnalyzer->getTemporalContext(
    $context['file_id'],
    $context['screenshot'],
    10 // ±10 seconds (longer for bills)
);
```

## Testing

### Run Unit Tests

```bash
# Run all resolution tests
includes/vendor/bin/phpunit tests/Resolution/

# Should show: OK (31 tests, 61 assertions)
```

### Test Coverage

**Name Matching:**
- Clean name extraction ✅
- Title/party/district removal ✅
- OCR error variations ✅
- Comma-separated names ✅
- Fuzzy scoring ✅
- Edge cases ✅

**Bill Matching:**
- Format parsing (all types) ✅
- Leading zero handling ✅
- OCR variations ✅
- Bill formatting ✅
- Multi-bill extraction ✅
- Invalid input handling ✅

**Tests Created:**
- `tests/Resolution/FuzzyMatcher/NameMatcherTest.php` (13 tests)
- `tests/Resolution/FuzzyMatcher/BillNumberMatcherTest.php` (18 tests)

## Performance

### Expected Performance

- **Throughput**: ~1000 entries in <5 minutes
- **Memory**: <256MB
- **Accuracy**: 85-95% resolution rate
- **False Positives**: <2%

### Optimization Features

- Session-based caching (no repeated DB queries)
- Batch processing support
- Efficient temporal window queries
- Database index utilization

## Success Metrics

### Target Accuracy

- **Legislators**: 85-95% resolution rate
- **Bills**: 90-98% resolution rate
- **False Positives**: <2%
- **Processing Time**: <5 minutes per 1000 entries

### Monitoring Points

- Resolution rate per type
- Average confidence scores
- Unresolved entry count
- Processing time per file
- Database query performance

## Troubleshooting

### Low Resolution Rate

If resolution rate is <85%:
1. Check database has data for the session
2. Verify `files.session_id` is correct
3. Lower confidence thresholds temporarily
4. Run with `--verbose` to see why matches fail

### False Matches

If wrong matches are occurring:
1. Increase confidence thresholds
2. Check meeting agenda data in `video_index_cache`
3. Review temporal context logic
4. Add more OCR error patterns if needed

### Slow Performance

If processing is slow:
1. Check database indexes exist
2. Use `--limit` to process fewer files at once
3. Monitor database query times

### No Matches Found

If no entries are being resolved:
1. Verify database connection
2. Check that `video_index` has entries with `linked_id IS NULL`
3. Ensure `files.session_id` is populated
4. Run with `--dry-run --verbose` to see detailed matching info

## Examples

### Example 1: Process Single File

```bash
$ php bin/resolve_raw_text.php --file-id=12345
Raw Text Resolution Phase
=========================

Processing file ID: 12345

Results:
========
Total entries: 245
Resolved: 229 (93.5%)
Unresolved: 16 (6.5%)

Total Time: 2m 34s
```

### Example 2: Dry Run with Verbose

```bash
$ php bin/resolve_raw_text.php --file-id=12345 --dry-run --verbose
DRY RUN MODE - No database updates will be made

Processing file ID: 12345

[INFO] Resolved legislator: "Sen. Bob Smith (R-6)" → Bob Smith (id=789, confidence=95.0%)
[INFO] Resolved bill: "HB1234" → HB1234 (id=456, confidence=100.0%)
[WARN] Unresolved legislator: "8ill Jones" (no match above 75%)

...
```

### Example 3: Process Only Bills

```bash
$ php bin/resolve_raw_text.php --type=bill --limit=5
Processing 5 files (bills only)

Results:
========
Files processed: 5
Total entries: 234
Resolved: 212 (90.6%)
Unresolved: 22 (9.4%)

Total Time: 4m 12s
```

## Extending the System

### Add New OCR Error Patterns

Edit `NameMatcher.php` or `BillNumberMatcher.php`:

```php
$substitutions = [
    '0' => ['O', 'o'],
    // Add new pattern:
    '2' => ['Z'],  // If 2 and Z are confused
];
```

### Add New Matching Algorithm

Extend `SimilarityCalculator.php`:

```php
public function myCustomSimilarity(string $str1, string $str2): float
{
    // Your algorithm here
    return $score;
}
```

Use in `NameMatcher.php`:

```php
$customScore = $this->similarity->myCustomSimilarity($str1, $str2);
$score = ($lev * 0.2 + $jaro * 0.4 + $custom * 0.4) * 100;
```

## Best Practices

1. **Always test with --dry-run first** before processing large batches
2. **Start with single file** to verify matching accuracy
3. **Monitor resolution rates** - should be 85%+ for legislators, 90%+ for bills
4. **Review unresolved entries** to identify systematic issues
5. **Use --limit** for incremental processing
6. **Check logs** for warnings and errors

## Files Created

### Source Code (8 files)
- `src/Resolution/RawTextResolver.php`
- `src/Resolution/LegislatorResolver.php`
- `src/Resolution/BillResolver.php`
- `src/Resolution/ContextAnalyzer.php`
- `src/Resolution/FuzzyMatcher/SimilarityCalculator.php`
- `src/Resolution/FuzzyMatcher/NameMatcher.php`
- `src/Resolution/FuzzyMatcher/BillNumberMatcher.php`
- `bin/resolve_raw_text.php`

### Tests (2 files)
- `tests/Resolution/FuzzyMatcher/NameMatcherTest.php` (13 tests)
- `tests/Resolution/FuzzyMatcher/BillNumberMatcherTest.php` (18 tests)

## Implementation Status

**Completed:** January 16, 2026
**Tests:** 31 tests, 61 assertions - all passing
**Status:** Production ready

---

For detailed implementation notes, see the source code comments in each resolver class.
