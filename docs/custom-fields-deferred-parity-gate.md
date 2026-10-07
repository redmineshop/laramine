# Deferred custom-field format gate

This is the Laramine checklist for `link`, `enumeration`, `attachment`, and `progressbar`. Those keys used to save a definition and reject every non-blank value. They now validate and store values on the same path as string, list, user, and version.

The custom fields checklist row is **VERIFIED** only by `tests/Parity/CustomFieldParityTest.php` against `tests/Parity/fixtures/redmine-7.0.1/` and `expectations/custom-fields/values.json`. A green smoke in the table below is Laramine behavior on MySQL 8. It is not that comparison. Do not describe the project as production-ready from this file. Do not treat this file as a 0.1 tag.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Format keys | `App\Domain\CustomFields\FieldFormatKey` |
| Link, enumeration, attachment, progress bar | `App\Domain\CustomFields\Formats` |
| Definition save (`multiple`, `searchable`, regexp, lengths) | `App\Domain\CustomFields\CustomFieldService` |
| Value sync, read shape, and attachment bind | `App\Domain\CustomFields\CustomValueService` |
| Enumeration option insert, reorder, activate, delete | `App\Domain\CustomFields\CustomFieldEnumerationService` |
| Attachment bytes, digest, disk directory | `App\Domain\Attachments\AttachmentService` |
| Authorized attachment download and link URL | `App\Domain\CustomFields\CustomFieldAssetAccess`, `GET /custom-fields/attachments/{id}`, `GET /custom-fields/links/{id}` |
| `cf_{id}` filters | `App\Domain\Queries\CustomFieldFilterSql` |

Domain behavior is described in [custom-fields.md](custom-fields.md). Filter types are described in [queries.md](queries.md). The checklist row is **VERIFIED** in [parity-checklist.md](parity-checklist.md) only by the parity test named there.

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
| Attachment download sends the file and increments `downloads` for a member who can see the issue. An outsider, a guest, and a member who cannot see a hidden field are refused. A cleared value and a missing file are not served | `tests/Feature/CustomFieldAssetHttpTest.php` `test_member_downloads_an_attachment_custom_value`, `test_outsider_and_guest_cannot_download_an_issue_attachment`, `test_hidden_attachment_field_follows_custom_field_roles`, and `test_cleared_or_unbound_attachment_is_not_served` |
| Link HTTP view returns the stored string and the formatted URL. A guest can read a public project link. A private project, an outsider on an issue, and another user's link are refused. The server does not request the URL | `tests/Feature/CustomFieldAssetHttpTest.php` `test_member_resolves_a_link_and_an_outsider_is_denied`, `test_public_project_link_is_readable_by_a_guest_and_a_private_project_is_not`, and `test_user_link_is_visible_to_that_user_and_an_admin_only` |

MySQL 8 is the database (`phpunit.xml`). These tests do not live under `tests/Parity`.

## Closed for the pin comparison

| Item | Status |
| --- | --- |
| Link outbound URL | **Closed.** `GET /custom-fields/links/{id}` returns the URL a client fetches. A value with no `url_pattern` and no `scheme://` prefix is returned with `http://` in front. The server does not request that URL. |
| Attachment delete over HTTP | **Closed.** `DELETE /custom-fields/attachments/{id}` removes a current custom-field file, its row, and the custom value when the actor can edit the host and the field. An issue host writes a `cf` journal detail. Journal and issue attachments stay off this route. |
| Version sharing | **Closed.** A version value must be available on the record's project under `none`, `descendants`, `hierarchy`, `tree`, or `system`. |
| Custom-field journal diffs | **Closed.** Issue update writes `journal_details` with `property = cf`. History lines for those rows are compared on the journals checklist row. |
| Redmine 7.0.1 comparison | **Closed for this row.** `tests/Parity/CustomFieldParityTest.php` compares the pin values and `expectations/custom-fields/values.json`. |

History presentation of `cf` details, `users_visibility` on user custom fields, and document, issue-priority, time-entry activity, and document-category custom field types stay outside that comparison. This file is not a 0.1 tag.
