# Deferred custom-field format gate

This is the Laramine checklist for `link`, `enumeration`, `attachment`, and `progressbar`. Those keys used to save a definition and reject every non-blank value. They now validate and store values on the same path as string, list, user, and version.

**Parity is NOT VERIFIED.** A green smoke in this repo is Laramine behavior on MySQL 8. It is not a comparison against a Redmine 7.0.1 database. Do not describe the project as production-ready from this file. Do not treat this file as a 0.1 tag.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Format keys | `App\Domain\CustomFields\FieldFormatKey` |
| Link, enumeration, attachment, progress bar | `App\Domain\CustomFields\Formats` |
| Definition save (`multiple`, `searchable`, regexp, lengths) | `App\Domain\CustomFields\CustomFieldService` |
| Value sync, read shape, and attachment bind | `App\Domain\CustomFields\CustomValueService` |
| Enumeration option insert, reorder, activate, delete | `App\Domain\CustomFields\CustomFieldEnumerationService` |
| Attachment bytes, digest, disk directory | `App\Domain\Attachments\AttachmentService` |
| `cf_{id}` filters | `App\Domain\Queries\CustomFieldFilterSql` |

Domain behavior is described in [custom-fields.md](custom-fields.md). Filter types are described in [queries.md](queries.md). The parity row stays **NOT VERIFIED** in [parity-checklist.md](parity-checklist.md).

## Smoke — these pass

| Check | Automated by |
| --- | --- |
| All 13 format keys are implemented | `tests/Unit/CustomFieldFormatTest.php` `test_registry_recognizes_every_redmine_format_key` |
| Link uses regexp, min length, and max length; `url_pattern` encodes `%value%`, `%id%`, `%project_id%`, `%project_identifier%`, and `%mN%`; the pattern text outside tokens is copied; not searchable or multiple | `tests/Unit/CustomFieldFormatTest.php` `test_link_uses_string_rules_and_stores_a_url_pattern` and `test_link_formatted_url_encodes_tokens_and_keeps_the_pattern` |
| Progress bar is an integer 0–100 on `ratio_interval`; not totalable | `test_progressbar_is_an_integer_percent_on_a_step` |
| Enumeration stores active ids of this field, including multiple | `tests/Unit/CustomFieldRecordFormatTest.php` `test_enumeration_format_stores_active_ids_for_this_field` |
| Attachment stores an id, checks the extension, and checks a bound container | `test_attachment_format_stores_an_id_and_checks_extension` |
| Issue create/update writes the four formats, reads the cast shape, and rejects a bad value | `tests/Feature/CustomFieldValueTest.php` `test_link_enumeration_progressbar_and_attachment_round_trip` |
| `cf_{id}` filters: link `string`, enumeration `list_optional`, attachment id `string`, progress bar `integer` | `tests/Unit/IssueQueryOperatorTest.php` `test_link_enumeration_attachment_and_progressbar_filters` |
| `any_searchable` skips a link field even when `searchable` is set on the row | `tests/Unit/IssueQueryFieldTest.php` `test_any_searchable_uses_subject_description_and_visible_custom_fields` |
| Query totals sum int and float custom fields. Progress bar is rejected. A hidden field is an error for a user who cannot see it | `tests/Feature/IssueQueryTest.php` `test_totals_sum_the_visible_issue_set` and `test_totals_reject_unknown_hidden_and_non_totalable_columns` |
| Attachment upload writes the file, SHA-256 `digest`, and `YYYY/MM` `disk_directory`, then binds an unbound row when the custom value is saved. Extension rules stay in `AttachmentFormat`. Clearing the value leaves the file and the container | `tests/Feature/CustomFieldAttachmentUploadTest.php` `test_upload_writes_digest_and_binds_when_the_custom_value_is_set` and `test_upload_rejects_a_bad_extension_and_a_foreign_container` |
| Enumeration options can be inserted, renamed, reordered, and activated or deactivated. Stored custom values keep the same ids. The current default cannot be deactivated | `tests/Feature/CustomFieldEnumerationOptionTest.php` `test_enumeration_options_reorder_and_values_stay_on_the_same_ids` |
| Enumeration option deletion removes an unused option. An option that custom values still store is rewritten to another option of the same field, including an inactive one, or left in place when no replacement is given. The current default cannot be deleted. A record that already stores the replacement keeps one row. Positions of the remaining options stay as stored | `tests/Feature/CustomFieldEnumerationOptionTest.php` `test_enumeration_option_delete_rewrites_values_or_refuses` |

MySQL 8 is the database (`phpunit.xml`). These tests do not live under `tests/Parity`.

## Still open

| Item | Status |
| --- | --- |
| Link HTTP view and live fetch | **Open.** `formattedUrl` builds an encoded URL and does not request it. There is no HTTP view. |
| Attachment download | **Open.** Bytes are stored on the local `attachments` disk. Nothing serves or deletes that file over HTTP. |
| Version sharing | **Open.** Unchanged. A version value must belong to the record's project. |
| Custom-field journal diffs | **Open.** Unchanged. Issue journals do not record custom-value edits. |
| Redmine 7.0.1 comparison | **Open.** No `tests/Parity` fixture compares these formats to Redmine. |

An open item does not authorize a parity-verified or production-ready claim.
