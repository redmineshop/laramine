# Deferred custom-field format gate

This is the Laramine checklist for `link`, `enumeration`, `attachment`, and `progressbar`. Those keys used to save a definition and reject every non-blank value. They now validate and store values on the same path as string, list, user, and version.

**Parity is NOT VERIFIED.** A green smoke in this repo is Laramine behavior on MySQL 8. It is not a comparison against a Redmine 7.0.1 database. Do not describe the project as production-ready from this file. Do not treat this file as a 0.1 tag.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Format keys | `App\Domain\CustomFields\FieldFormatKey` |
| Link, enumeration, attachment, progress bar | `App\Domain\CustomFields\Formats` |
| Definition save (`multiple`, `searchable`, regexp, lengths) | `App\Domain\CustomFields\CustomFieldService` |
| Value sync and read shape | `App\Domain\CustomFields\CustomValueService` |
| `cf_{id}` filters | `App\Domain\Queries\CustomFieldFilterSql` |

Domain behavior is described in [custom-fields.md](custom-fields.md). Filter types are described in [queries.md](queries.md). The parity row stays **NOT VERIFIED** in [parity-checklist.md](parity-checklist.md).

## Smoke — these pass

| Check | Automated by |
| --- | --- |
| All 13 format keys are implemented | `tests/Unit/CustomFieldFormatTest.php` `test_registry_recognizes_every_redmine_format_key` |
| Link uses regexp, min length, and max length; `url_pattern` substitutes `%value%` and `%id%`; not searchable or multiple | `test_link_uses_string_rules_and_stores_a_url_pattern` |
| Progress bar is an integer 0–100 on `ratio_interval`; not totalable | `test_progressbar_is_an_integer_percent_on_a_step` |
| Enumeration stores active ids of this field, including multiple | `tests/Unit/CustomFieldRecordFormatTest.php` `test_enumeration_format_stores_active_ids_for_this_field` |
| Attachment stores an id, checks the extension, and checks a bound container | `test_attachment_format_stores_an_id_and_checks_extension` |
| Issue create/update writes the four formats, reads the cast shape, and rejects a bad value | `tests/Feature/CustomFieldValueTest.php` `test_link_enumeration_progressbar_and_attachment_round_trip` |
| `cf_{id}` filters: link `string`, enumeration `list_optional`, attachment id `string`, progress bar `integer` | `tests/Unit/IssueQueryOperatorTest.php` `test_link_enumeration_attachment_and_progressbar_filters` |
| `any_searchable` skips a link field even when `searchable` is set on the row | `tests/Unit/IssueQueryFieldTest.php` `test_any_searchable_uses_subject_description_and_visible_custom_fields` |

MySQL 8 is the database (`phpunit.xml`). These tests do not live under `tests/Parity`.

## Still open

| Item | Status |
| --- | --- |
| Attachment upload pipeline | **Open.** No disk file, `digest`, or `disk_directory` is written. An existing `attachments` row is validated by id. A row with an empty container is accepted and is not bound by this slice. |
| Enumeration option editing | **Open.** Rows in `custom_field_enumerations` are read. Nothing in this slice inserts, reorders, or deactivates them except a test. |
| Link display | **Open.** `formattedUrl` substitutes two tokens. It does not encode the value or request the URL. There is no HTTP view. |
| Query totals | **Open.** Int and float report `supportsTotal`. Progress bar reports false. IssueQuery does not sum custom fields. |
| Version sharing | **Open.** Unchanged. A version value must belong to the record's project. |
| Custom-field journal diffs | **Open.** Unchanged. Issue journals do not record custom-value edits. |
| Redmine 7.0.1 comparison | **Open.** No `tests/Parity` fixture compares these formats to Redmine. |

An open item does not authorize a parity-verified or production-ready claim.
