# EleGuard Rail — Milestone 9C: Test Strategy & Quality Assurance Framework (Refined)

**Document:** `docs/phase9/milestone_9c_test_strategy.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN-ONLY (Test Specification & Protocols; Verified on Isolated Clone)  
**Target Test Suite:** `tests/test_milestone_9c_temporal.php`  
**Execution Environment:** Isolated Test Database (`eleguard_rail_test`) on MariaDB Port 3308

---

## 1. Quality Assurance Objective & Safety Mandate

The primary objective of the Milestone 9C test strategy is to ensure that the introduction of temporal intelligence (sighting dates, sighting times, and precision categories):
1. Correctly models all real-world field observation scenarios (exact, approximate with uncertainty interval, unknown).
2. Prevents temporal data corruption (future dates, invalid formats, midnight boundary errors).
3. Preserves immutable operational audit timestamps (`created_at`, `updated_at`).
4. **Preserves historical baseline records without fabricating sighting dates (`sighting_date IS NULL`).**
5. Maintains 100% backward compatibility with existing GPS, AI detection, and text-only reporting workflows.
6. Strictly adheres to zero operational data mutations by conducting all migration and persistence testing within an isolated test database.

---

## 2. Test Group Architecture & Specification Matrix

The planned test suite `tests/test_milestone_9c_temporal.php` defines **32 comprehensive test cases** organized into 8 functional test groups:

```
+----------------------------------------------------------------------------------------------------+
|                         MILESTONE 9C TEMPORAL TEST ARCHITECTURE (32 TESTS)                         |
+----------------------------------------------------------------------------------------------------+
|                                                                                                    |
|  [ Group 1: Temporal Validation Unit Tests (6 Tests) ]                                             |
|  * Valid date/time * Invalid calendar dates * Future date rejection * Future time rejection        |
|  * Minimum historical bound (1900) * Non-numeric time string rejection                             |
|                                                                                                    |
|  [ Group 2: Precision & Unknown-Time State Engine (5 Tests) ]                                      |
|  * 'exact' precision enforces HH:MM * 'approximate' precision validates preset & uncertainty window |
|  * 'unknown' precision sets time = NULL * 'unknown' precision rejects explicit time input           |
|  * Custom uncertainty interval validation (e.g. 15-180 minutes)                                    |
|                                                                                                    |
|  [ Group 3: Midnight & Diurnal/Nocturnal Boundary Cases (4 Tests) ]                                |
|  * 23:59:59 correctly classified as Night * 00:00:00 midnight handled without converting to NULL  |
|  * 00:01:00 correctly classified as Night * 06:00:00 / 18:00:00 exact boundary transition         |
|                                                                                                    |
|  [ Group 4: Timezone Integrity & Asia/Colombo Canon (3 Tests) ]                                    |
|  * Canonical timezone enforced as Asia/Colombo * UTC vs Colombo +05:30 offset verification        |
|  * Daylight Saving Time immunity (Colombo observes standard time year-round)                      |
|                                                                                                    |
|  [ Group 5: Hardware GPS Fix Timestamp Capture (3 Tests) ]                                         |
|  * DOMTimeStamp extraction * Conversion to MySQL DATETIME format * Historical GPS fix preservation |
|                                                                                                    |
|  [ Group 6: Risk Engine Temporal Integration & Safety Policy (4 Tests) ]                           |
|  * Exact night sighting (02:30 AM) receives +2 bonus regardless of daytime submission              |
|  * Day sighting (14:00 PM) submitted at night does NOT receive night bonus                        |
|  * Approximate nocturnal preset (Night: 20:00-00:00) receives +2 bonus                            |
|  * Straddling approximate preset (Dusk: 17:30-19:00) adopts conservative non-bonus policy        |
|  * Unknown sighting time NEVER receives night bonus                                                |
|                                                                                                    |
|  [ Group 7: Migration & Historical Data Preservation (4 Tests) ]                                   |
|  * Schema migration 03 executes cleanly * Rollback migration 03 restores exact prior schema        |
|  * Historical records retain sighting_date = NULL, sighting_time = NULL, precision = 'unknown'     |
|  * created_at and updated_at remain 100% untouched across all 43 existing records                  |
|                                                                                                    |
|  [ Group 8: End-to-End Non-Regression (3 Tests) ]                                                  |
|  * Text-only submission with date/time succeeds * GPS submission with date/time succeeds          |
|  * AI detection pipeline executes with temporal metadata attached                                 |
|                                                                                                    |
+----------------------------------------------------------------------------------------------------+
```

---

## 3. Specific Test Assertions for Refined Rules

### Historical Data Preservation Assertions
- In `testMigrationIntegrity()`:
  ```php
  $nullDateCount = (int)$db->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_date IS NULL")->fetchColumn();
  $nullTimeCount = (int)$db->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time IS NULL")->fetchColumn();
  $unknownPrecCount = (int)$db->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_precision = 'unknown'")->fetchColumn();

  $this->assert($nullDateCount === 43, "Historical records retain sighting_date = NULL (zero assumed dates)");
  $this->assert($nullTimeCount === 43, "Historical records retain sighting_time = NULL (zero assumed times)");
  $this->assert($unknownPrecCount === 43, "Historical records retain precision = 'unknown'");
  ```

### Risk Engine Safety Assertions
- In `testRiskEngineTemporalSafety()`:
  - Exact night sighting (`02:30:00`): Assert score includes +2 night bonus.
  - Day sighting submitted at night: Assert score does NOT include night bonus.
  - Straddling dusk sighting (`18:15:00` $\pm 45$ mins): Assert conservative baseline (no night bonus).
  - Unknown time sighting (`time = null`, `precision = 'unknown'`): Assert score evaluates standard baseline without night bonus.
  - Assert `created_at` was NOT accessed by `RiskService::calculateRiskScore`.
