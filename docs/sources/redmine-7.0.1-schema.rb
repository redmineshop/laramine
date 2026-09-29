# Provenance (inventory source — NOT Redmine application source)
# Redmine version: 7.0.1 (MAJOR=7 MINOR=0 TINY=1)
# Release date: 2026-08-26
# Upstream tarball: https://www.redmine.org/releases/redmine-7.0.1.tar.gz
# GitHub mirror tag: https://github.com/redmine/redmine/tree/7.0.1 (commit 752772866745192659850a632cd2c7a159856994)
# Official image: docker.io/library/redmine:7.0.1 (REDMINE_VERSION=7.0.1)
# Generated: rake db:migrate db:schema:dump against that image (SQLite adapter dump)
# Note: upstream gitignores db/schema.rb; this file is a regenerated structure dump for ETL/port inventory only.
# License of Redmine is GPLv2; this dump is table/column structure for reimplementation — do not copy app Ruby.

# This file is auto-generated from the current state of the database. Instead
# of editing this file, please use the migrations feature of Active Record to
# incrementally modify your database, and then regenerate this schema definition.
#
# This file is the source Rails uses to define your schema when running `bin/rails
# db:schema:load`. When creating a new database, `bin/rails db:schema:load` tends to
# be faster and is potentially less error prone than running all of your
# migrations from scratch. Old migrations may fail to apply correctly if those
# migrations use external dependencies or application code.
#
# It's strongly recommended that you check this file into your version control system.

ActiveRecord::Schema[8.1].define(version: 2026_05_20_164915) do
  create_table "attachments", force: :cascade do |t|
    t.integer "author_id", default: 0, null: false
    t.integer "container_id"
    t.string "container_type", limit: 30
    t.string "content_type"
    t.datetime "created_on", precision: nil
    t.string "description"
    t.string "digest", limit: 64
    t.string "disk_directory"
    t.string "disk_filename", default: "", null: false
    t.integer "downloads", default: 0, null: false
    t.string "filename", default: "", null: false
    t.integer "filesize", limit: 8, default: 0, null: false
    t.index ["author_id"], name: "index_attachments_on_author_id"
    t.index ["container_id", "container_type"], name: "index_attachments_on_container_id_and_container_type"
    t.index ["created_on"], name: "index_attachments_on_created_on"
    t.index ["disk_filename"], name: "index_attachments_on_disk_filename"
  end

  create_table "auth_sources", force: :cascade do |t|
    t.string "account"
    t.string "account_password", default: ""
    t.string "attr_firstname", limit: 30
    t.string "attr_lastname", limit: 30
    t.string "attr_login", limit: 30
    t.string "attr_mail", limit: 30
    t.string "base_dn", limit: 255
    t.text "filter"
    t.string "host", limit: 60
    t.string "name", limit: 60, default: "", null: false
    t.boolean "onthefly_register", default: false, null: false
    t.integer "port"
    t.integer "timeout"
    t.boolean "tls", default: false, null: false
    t.string "type", limit: 30, default: "", null: false
    t.boolean "verify_peer", default: true, null: false
    t.index ["id", "type"], name: "index_auth_sources_on_id_and_type"
  end

  create_table "boards", force: :cascade do |t|
    t.string "description"
    t.integer "last_message_id"
    t.integer "messages_count", default: 0, null: false
    t.string "name", default: "", null: false
    t.integer "parent_id"
    t.integer "position"
    t.integer "project_id", null: false
    t.integer "topics_count", default: 0, null: false
    t.index ["last_message_id"], name: "index_boards_on_last_message_id"
    t.index ["project_id"], name: "boards_project_id"
  end

  create_table "changes", force: :cascade do |t|
    t.string "action", limit: 1, default: "", null: false
    t.string "branch"
    t.integer "changeset_id", null: false
    t.text "from_path"
    t.string "from_revision"
    t.text "path", null: false
    t.string "revision"
    t.index ["changeset_id"], name: "changesets_changeset_id"
  end

  create_table "changeset_parents", id: false, force: :cascade do |t|
    t.integer "changeset_id", null: false
    t.integer "parent_id", null: false
    t.index ["changeset_id"], name: "changeset_parents_changeset_ids"
    t.index ["parent_id"], name: "changeset_parents_parent_ids"
  end

  create_table "changesets", force: :cascade do |t|
    t.text "comments"
    t.date "commit_date"
    t.datetime "committed_on", precision: nil, null: false
    t.string "committer"
    t.integer "repository_id", null: false
    t.string "revision", null: false
    t.string "scmid"
    t.integer "user_id"
    t.index ["committed_on"], name: "index_changesets_on_committed_on"
    t.index ["repository_id", "revision"], name: "changesets_repos_rev", unique: true
    t.index ["repository_id", "scmid"], name: "changesets_repos_scmid"
    t.index ["repository_id"], name: "index_changesets_on_repository_id"
    t.index ["user_id"], name: "index_changesets_on_user_id"
  end

  create_table "changesets_issues", id: false, force: :cascade do |t|
    t.integer "changeset_id", null: false
    t.integer "issue_id", null: false
    t.index ["changeset_id", "issue_id"], name: "changesets_issues_ids", unique: true
    t.index ["issue_id"], name: "index_changesets_issues_on_issue_id"
  end

  create_table "comments", force: :cascade do |t|
    t.integer "author_id", default: 0, null: false
    t.integer "commented_id", default: 0, null: false
    t.string "commented_type", limit: 30, default: "", null: false
    t.text "content"
    t.datetime "created_on", precision: nil, null: false
    t.datetime "updated_on", precision: nil, null: false
    t.index ["author_id"], name: "index_comments_on_author_id"
    t.index ["commented_id", "commented_type"], name: "index_comments_on_commented_id_and_commented_type"
  end

  create_table "custom_field_enumerations", force: :cascade do |t|
    t.boolean "active", default: true, null: false
    t.integer "custom_field_id", null: false
    t.string "name", null: false
    t.integer "position", default: 1, null: false
  end

  create_table "custom_fields", force: :cascade do |t|
    t.text "default_value"
    t.text "description"
    t.boolean "editable", default: true
    t.string "field_format", limit: 30, default: "", null: false
    t.text "format_store"
    t.boolean "is_filter", default: false, null: false
    t.boolean "is_for_all", default: false, null: false
    t.boolean "is_required", default: false, null: false
    t.integer "max_length"
    t.integer "min_length"
    t.boolean "multiple", default: false
    t.string "name", limit: 30, default: "", null: false
    t.integer "position"
    t.text "possible_values"
    t.string "regexp", default: ""
    t.boolean "searchable", default: false
    t.string "type", limit: 30, default: "", null: false
    t.boolean "visible", default: true, null: false
    t.index ["id", "type"], name: "index_custom_fields_on_id_and_type"
  end

  create_table "custom_fields_projects", id: false, force: :cascade do |t|
    t.integer "custom_field_id", default: 0, null: false
    t.integer "project_id", default: 0, null: false
    t.index ["custom_field_id", "project_id"], name: "index_custom_fields_projects_on_custom_field_id_and_project_id", unique: true
  end

  create_table "custom_fields_roles", id: false, force: :cascade do |t|
    t.integer "custom_field_id", null: false
    t.integer "role_id", null: false
    t.index ["custom_field_id", "role_id"], name: "custom_fields_roles_ids", unique: true
  end

  create_table "custom_fields_trackers", id: false, force: :cascade do |t|
    t.integer "custom_field_id", default: 0, null: false
    t.integer "tracker_id", default: 0, null: false
    t.index ["custom_field_id", "tracker_id"], name: "index_custom_fields_trackers_on_custom_field_id_and_tracker_id", unique: true
  end

  create_table "custom_values", force: :cascade do |t|
    t.integer "custom_field_id", default: 0, null: false
    t.integer "customized_id", default: 0, null: false
    t.string "customized_type", limit: 30, default: "", null: false
    t.text "value"
    t.index ["custom_field_id"], name: "index_custom_values_on_custom_field_id"
    t.index ["customized_type", "customized_id", "custom_field_id"], name: "custom_values_customized_custom_field"
  end

  create_table "documents", force: :cascade do |t|
    t.integer "category_id", default: 0, null: false
    t.datetime "created_on", precision: nil
    t.text "description"
    t.integer "project_id", default: 0, null: false
    t.string "title", default: "", null: false
    t.index ["category_id"], name: "index_documents_on_category_id"
    t.index ["created_on"], name: "index_documents_on_created_on"
    t.index ["project_id"], name: "documents_project_id"
  end

  create_table "email_addresses", force: :cascade do |t|
    t.string "address", null: false
    t.datetime "created_on", precision: nil, null: false
    t.boolean "is_default", default: false, null: false
    t.boolean "notify", default: true, null: false
    t.datetime "updated_on", precision: nil, null: false
    t.integer "user_id", null: false
    t.index ["user_id"], name: "index_email_addresses_on_user_id"
  end

  create_table "enabled_modules", force: :cascade do |t|
    t.string "name", null: false
    t.integer "project_id"
    t.index ["project_id"], name: "enabled_modules_project_id"
  end

  create_table "enumerations", force: :cascade do |t|
    t.boolean "active", default: true, null: false
    t.boolean "is_default", default: false, null: false
    t.string "name", limit: 30, default: "", null: false
    t.integer "parent_id"
    t.integer "position"
    t.string "position_name", limit: 30
    t.integer "project_id"
    t.string "type"
    t.index ["id", "type"], name: "index_enumerations_on_id_and_type"
    t.index ["project_id"], name: "index_enumerations_on_project_id"
  end

  create_table "groups_users", id: false, force: :cascade do |t|
    t.integer "group_id", null: false
    t.integer "user_id", null: false
    t.index ["group_id", "user_id"], name: "groups_users_ids", unique: true
  end

  create_table "import_items", force: :cascade do |t|
    t.integer "import_id", null: false
    t.text "message"
    t.integer "obj_id"
    t.integer "position", null: false
    t.string "unique_id"
    t.index ["import_id", "unique_id"], name: "index_import_items_on_import_id_and_unique_id"
  end

  create_table "imports", force: :cascade do |t|
    t.datetime "created_at", precision: nil, null: false
    t.string "filename"
    t.boolean "finished", default: false, null: false
    t.text "settings"
    t.integer "total_items"
    t.string "type"
    t.datetime "updated_at", precision: nil, null: false
    t.integer "user_id", null: false
  end

  create_table "issue_categories", force: :cascade do |t|
    t.integer "assigned_to_id"
    t.string "name", limit: 60, default: "", null: false
    t.integer "project_id", default: 0, null: false
    t.index ["assigned_to_id"], name: "index_issue_categories_on_assigned_to_id"
    t.index ["project_id"], name: "issue_categories_project_id"
  end

  create_table "issue_relations", force: :cascade do |t|
    t.integer "delay"
    t.integer "issue_from_id", null: false
    t.integer "issue_to_id", null: false
    t.string "relation_type", default: "", null: false
    t.index ["issue_from_id", "issue_to_id"], name: "index_issue_relations_on_issue_from_id_and_issue_to_id", unique: true
    t.index ["issue_from_id"], name: "index_issue_relations_on_issue_from_id"
    t.index ["issue_to_id"], name: "index_issue_relations_on_issue_to_id"
  end

  create_table "issue_statuses", force: :cascade do |t|
    t.integer "default_done_ratio"
    t.string "description"
    t.boolean "is_closed", default: false, null: false
    t.string "name", limit: 30, default: "", null: false
    t.integer "position"
    t.index ["is_closed"], name: "index_issue_statuses_on_is_closed"
    t.index ["position"], name: "index_issue_statuses_on_position"
  end

  create_table "issues", force: :cascade do |t|
    t.integer "assigned_to_id"
    t.integer "author_id", null: false
    t.integer "category_id"
    t.datetime "closed_on", precision: nil
    t.datetime "created_on", precision: nil
    t.text "description"
    t.integer "done_ratio", default: 0, null: false
    t.date "due_date"
    t.float "estimated_hours"
    t.integer "fixed_version_id"
    t.boolean "is_private", default: false, null: false
    t.integer "lft"
    t.integer "lock_version", default: 0, null: false
    t.integer "parent_id"
    t.integer "priority_id", null: false
    t.integer "project_id", null: false
    t.integer "rgt"
    t.integer "root_id"
    t.date "start_date"
    t.integer "status_id", null: false
    t.string "subject", default: "", null: false
    t.integer "tracker_id", null: false
    t.datetime "updated_on", precision: nil
    t.index ["assigned_to_id"], name: "index_issues_on_assigned_to_id"
    t.index ["author_id"], name: "index_issues_on_author_id"
    t.index ["category_id"], name: "index_issues_on_category_id"
    t.index ["created_on"], name: "index_issues_on_created_on"
    t.index ["fixed_version_id"], name: "index_issues_on_fixed_version_id"
    t.index ["parent_id"], name: "index_issues_on_parent_id"
    t.index ["priority_id"], name: "index_issues_on_priority_id"
    t.index ["project_id"], name: "issues_project_id"
    t.index ["root_id", "lft", "rgt"], name: "index_issues_on_root_id_and_lft_and_rgt"
    t.index ["status_id"], name: "index_issues_on_status_id"
    t.index ["tracker_id"], name: "index_issues_on_tracker_id"
  end

  create_table "journal_details", force: :cascade do |t|
    t.integer "journal_id", default: 0, null: false
    t.text "old_value"
    t.string "prop_key", limit: 30, default: "", null: false
    t.string "property", limit: 30, default: "", null: false
    t.text "value"
    t.index ["journal_id"], name: "journal_details_journal_id"
  end

  create_table "journals", force: :cascade do |t|
    t.datetime "created_on", precision: nil, null: false
    t.integer "journalized_id", default: 0, null: false
    t.string "journalized_type", limit: 30, default: "", null: false
    t.text "notes"
    t.boolean "private_notes", default: false, null: false
    t.integer "updated_by_id"
    t.datetime "updated_on", precision: nil
    t.integer "user_id", default: 0, null: false
    t.index ["created_on"], name: "index_journals_on_created_on"
    t.index ["journalized_id", "journalized_type"], name: "journals_journalized_id"
    t.index ["journalized_id"], name: "index_journals_on_journalized_id"
    t.index ["user_id"], name: "index_journals_on_user_id"
  end

  create_table "member_roles", force: :cascade do |t|
    t.integer "inherited_from"
    t.integer "member_id", null: false
    t.integer "role_id", null: false
    t.index ["inherited_from"], name: "index_member_roles_on_inherited_from"
    t.index ["member_id"], name: "index_member_roles_on_member_id"
    t.index ["role_id"], name: "index_member_roles_on_role_id"
  end

  create_table "members", force: :cascade do |t|
    t.datetime "created_on", precision: nil
    t.boolean "mail_notification", default: false, null: false
    t.integer "project_id", default: 0, null: false
    t.integer "user_id", default: 0, null: false
    t.index ["project_id"], name: "index_members_on_project_id"
    t.index ["user_id", "project_id"], name: "index_members_on_user_id_and_project_id", unique: true
    t.index ["user_id"], name: "index_members_on_user_id"
  end

  create_table "messages", force: :cascade do |t|
    t.integer "author_id"
    t.integer "board_id", null: false
    t.text "content"
    t.datetime "created_on", precision: nil, null: false
    t.integer "last_reply_id"
    t.boolean "locked", default: false
    t.integer "parent_id"
    t.integer "replies_count", default: 0, null: false
    t.integer "sticky", default: 0
    t.string "subject", default: "", null: false
    t.datetime "updated_on", precision: nil, null: false
    t.index ["author_id"], name: "index_messages_on_author_id"
    t.index ["board_id"], name: "messages_board_id"
    t.index ["created_on"], name: "index_messages_on_created_on"
    t.index ["last_reply_id"], name: "index_messages_on_last_reply_id"
    t.index ["parent_id"], name: "messages_parent_id"
  end

  create_table "news", force: :cascade do |t|
    t.integer "author_id", default: 0, null: false
    t.integer "comments_count", default: 0, null: false
    t.datetime "created_on", precision: nil
    t.text "description"
    t.integer "project_id"
    t.string "summary", limit: 255, default: ""
    t.string "title", limit: 60, default: "", null: false
    t.index ["author_id"], name: "index_news_on_author_id"
    t.index ["created_on"], name: "index_news_on_created_on"
    t.index ["project_id"], name: "news_project_id"
  end

  create_table "oauth_access_grants", force: :cascade do |t|
    t.integer "application_id", null: false
    t.string "code_challenge"
    t.string "code_challenge_method"
    t.datetime "created_at", null: false
    t.integer "expires_in", null: false
    t.text "redirect_uri", null: false
    t.integer "resource_owner_id", null: false
    t.datetime "revoked_at"
    t.text "scopes"
    t.string "token", null: false
    t.index ["application_id"], name: "index_oauth_access_grants_on_application_id"
    t.index ["token"], name: "index_oauth_access_grants_on_token", unique: true
  end

  create_table "oauth_access_tokens", force: :cascade do |t|
    t.integer "application_id"
    t.datetime "created_at", null: false
    t.integer "expires_in"
    t.string "previous_refresh_token", default: "", null: false
    t.string "refresh_token"
    t.integer "resource_owner_id"
    t.datetime "revoked_at"
    t.text "scopes"
    t.string "token", null: false
    t.index ["application_id"], name: "index_oauth_access_tokens_on_application_id"
    t.index ["refresh_token"], name: "index_oauth_access_tokens_on_refresh_token", unique: true
    t.index ["resource_owner_id"], name: "index_oauth_access_tokens_on_resource_owner_id"
    t.index ["token"], name: "index_oauth_access_tokens_on_token", unique: true
  end

  create_table "oauth_applications", force: :cascade do |t|
    t.boolean "confidential", default: true, null: false
    t.datetime "created_at", null: false
    t.string "name", null: false
    t.text "redirect_uri", null: false
    t.text "scopes", null: false
    t.string "secret", null: false
    t.string "uid", null: false
    t.datetime "updated_at", null: false
    t.index ["uid"], name: "index_oauth_applications_on_uid", unique: true
  end

  create_table "projects", force: :cascade do |t|
    t.datetime "created_on", precision: nil
    t.integer "default_assigned_to_id"
    t.integer "default_issue_query_id"
    t.integer "default_version_id"
    t.text "description"
    t.string "homepage", default: ""
    t.string "identifier"
    t.boolean "inherit_members", default: false, null: false
    t.boolean "is_public", default: true, null: false
    t.integer "lft"
    t.string "name", default: "", null: false
    t.integer "parent_id"
    t.integer "rgt"
    t.integer "status", default: 1, null: false
    t.datetime "updated_on", precision: nil
    t.index ["identifier"], name: "index_projects_on_identifier", unique: true
    t.index ["lft"], name: "index_projects_on_lft"
    t.index ["rgt"], name: "index_projects_on_rgt"
  end

  create_table "projects_trackers", id: false, force: :cascade do |t|
    t.integer "project_id", default: 0, null: false
    t.integer "tracker_id", default: 0, null: false
    t.index ["project_id", "tracker_id"], name: "projects_trackers_unique", unique: true
    t.index ["project_id"], name: "projects_trackers_project_id"
  end

  create_table "projects_webhooks", force: :cascade do |t|
    t.integer "project_id", null: false
    t.integer "webhook_id", null: false
    t.index ["project_id"], name: "index_projects_webhooks_on_project_id"
    t.index ["webhook_id"], name: "index_projects_webhooks_on_webhook_id"
  end

  create_table "queries", force: :cascade do |t|
    t.text "column_names"
    t.string "description"
    t.text "filters"
    t.string "group_by"
    t.string "name", default: "", null: false
    t.text "options"
    t.integer "project_id"
    t.text "sort_criteria"
    t.string "type"
    t.integer "user_id", default: 0, null: false
    t.integer "visibility", default: 0
    t.index ["project_id"], name: "index_queries_on_project_id"
    t.index ["user_id"], name: "index_queries_on_user_id"
  end

  create_table "queries_roles", id: false, force: :cascade do |t|
    t.integer "query_id", null: false
    t.integer "role_id", null: false
    t.index ["query_id", "role_id"], name: "queries_roles_ids", unique: true
  end

  create_table "reactions", force: :cascade do |t|
    t.datetime "created_at", null: false
    t.integer "reactable_id", null: false
    t.string "reactable_type", null: false
    t.datetime "updated_at", null: false
    t.integer "user_id", null: false
    t.index ["reactable_type", "reactable_id", "id"], name: "index_reactions_on_reactable_type_and_reactable_id_and_id"
    t.index ["reactable_type", "reactable_id", "user_id"], name: "index_reactions_on_reactable_type_and_reactable_id_and_user_id", unique: true
    t.index ["reactable_type", "reactable_id"], name: "index_reactions_on_reactable"
    t.index ["user_id"], name: "index_reactions_on_user_id"
  end

  create_table "repositories", force: :cascade do |t|
    t.datetime "created_on"
    t.text "extra_info"
    t.string "identifier"
    t.boolean "is_default", default: false
    t.string "log_encoding", limit: 64
    t.string "login", limit: 60, default: ""
    t.string "password", default: ""
    t.string "path_encoding", limit: 64
    t.integer "project_id", default: 0, null: false
    t.string "root_url", limit: 255, default: ""
    t.string "type"
    t.string "url", default: "", null: false
    t.index ["project_id"], name: "index_repositories_on_project_id"
  end

  create_table "roles", force: :cascade do |t|
    t.boolean "all_roles_managed", default: true, null: false
    t.boolean "assignable", default: true
    t.integer "builtin", default: 0, null: false
    t.integer "default_time_entry_activity_id"
    t.string "issues_visibility", limit: 30, default: "default", null: false
    t.string "name", limit: 255, default: ""
    t.text "permissions"
    t.integer "position"
    t.text "settings"
    t.string "time_entries_visibility", limit: 30, default: "all", null: false
    t.string "users_visibility", limit: 30, default: "members_of_visible_projects", null: false
  end

  create_table "roles_managed_roles", id: false, force: :cascade do |t|
    t.integer "managed_role_id", null: false
    t.integer "role_id", null: false
    t.index ["role_id", "managed_role_id"], name: "index_roles_managed_roles_on_role_id_and_managed_role_id", unique: true
  end

  create_table "settings", force: :cascade do |t|
    t.string "name", limit: 255, default: "", null: false
    t.datetime "updated_on"
    t.text "value"
    t.index ["name"], name: "index_settings_on_name"
  end

  create_table "time_entries", force: :cascade do |t|
    t.integer "activity_id", null: false
    t.integer "author_id"
    t.string "comments", limit: 1024
    t.datetime "created_on", precision: nil, null: false
    t.float "hours", null: false
    t.integer "issue_id"
    t.integer "project_id", null: false
    t.date "spent_on", null: false
    t.integer "tmonth", null: false
    t.integer "tweek", null: false
    t.integer "tyear", null: false
    t.datetime "updated_on", precision: nil, null: false
    t.integer "user_id", null: false
    t.index ["activity_id"], name: "index_time_entries_on_activity_id"
    t.index ["created_on"], name: "index_time_entries_on_created_on"
    t.index ["issue_id"], name: "time_entries_issue_id"
    t.index ["project_id"], name: "time_entries_project_id"
    t.index ["user_id"], name: "index_time_entries_on_user_id"
  end

  create_table "tokens", force: :cascade do |t|
    t.string "action", limit: 30, default: "", null: false
    t.datetime "created_on", precision: nil, null: false
    t.datetime "updated_on"
    t.integer "user_id", default: 0, null: false
    t.string "value", limit: 40, default: "", null: false
    t.index ["user_id"], name: "index_tokens_on_user_id"
    t.index ["value"], name: "tokens_value", unique: true
  end

  create_table "trackers", force: :cascade do |t|
    t.integer "default_status_id"
    t.string "description"
    t.integer "fields_bits", default: 0
    t.boolean "is_in_roadmap", default: true, null: false
    t.string "name", limit: 30, default: "", null: false
    t.integer "position"
    t.boolean "private_by_default", default: false, null: false
  end

  create_table "user_preferences", force: :cascade do |t|
    t.boolean "hide_mail", default: true
    t.text "others"
    t.string "time_zone"
    t.integer "user_id", default: 0, null: false
    t.index ["user_id"], name: "index_user_preferences_on_user_id"
  end

  create_table "users", force: :cascade do |t|
    t.boolean "admin", default: false, null: false
    t.integer "auth_source_id"
    t.datetime "created_on", precision: nil
    t.string "firstname", limit: 30, default: "", null: false
    t.string "hashed_password", limit: 40, default: "", null: false
    t.string "language", limit: 5, default: ""
    t.datetime "last_login_on", precision: nil
    t.string "lastname", limit: 255, default: "", null: false
    t.string "login", default: "", null: false
    t.string "mail_notification", default: "", null: false
    t.boolean "must_change_passwd", default: false, null: false
    t.datetime "passwd_changed_on", precision: nil
    t.string "salt", limit: 64
    t.integer "status", default: 1, null: false
    t.boolean "twofa_required", default: false
    t.string "twofa_scheme"
    t.string "twofa_totp_key"
    t.integer "twofa_totp_last_used_at"
    t.string "type"
    t.datetime "updated_on", precision: nil
    t.index "(lower(login))", name: "index_users_on_lower_login"
    t.index ["auth_source_id"], name: "index_users_on_auth_source_id"
    t.index ["id", "type"], name: "index_users_on_id_and_type"
    t.index ["login"], name: "index_users_on_login"
    t.index ["type"], name: "index_users_on_type"
  end

  create_table "versions", force: :cascade do |t|
    t.datetime "created_on", precision: nil
    t.string "description", default: ""
    t.date "effective_date"
    t.string "name"
    t.integer "project_id", default: 0, null: false
    t.string "sharing", default: "none", null: false
    t.string "status", default: "open"
    t.datetime "updated_on", precision: nil
    t.string "wiki_page_title"
    t.index ["project_id"], name: "versions_project_id"
    t.index ["sharing"], name: "index_versions_on_sharing"
  end

  create_table "watchers", force: :cascade do |t|
    t.integer "user_id"
    t.integer "watchable_id", default: 0, null: false
    t.string "watchable_type", default: "", null: false
    t.index ["user_id", "watchable_type"], name: "watchers_user_id_type"
    t.index ["user_id"], name: "index_watchers_on_user_id"
    t.index ["watchable_id", "watchable_type"], name: "index_watchers_on_watchable_id_and_watchable_type"
  end

  create_table "webhooks", force: :cascade do |t|
    t.boolean "active", default: false, null: false
    t.datetime "created_at", null: false
    t.text "events"
    t.string "secret"
    t.datetime "updated_at", null: false
    t.string "url", limit: 2000, null: false
    t.integer "user_id", null: false
    t.index ["active"], name: "index_webhooks_on_active"
    t.index ["user_id"], name: "index_webhooks_on_user_id"
  end

  create_table "wiki_content_versions", force: :cascade do |t|
    t.integer "author_id"
    t.string "comments", limit: 1024, default: ""
    t.string "compression", limit: 6, default: ""
    t.binary "data"
    t.integer "page_id", null: false
    t.datetime "updated_on", precision: nil, null: false
    t.integer "version", null: false
    t.integer "wiki_content_id", null: false
    t.index ["updated_on"], name: "index_wiki_content_versions_on_updated_on"
    t.index ["wiki_content_id"], name: "wiki_content_versions_wcid"
  end

  create_table "wiki_contents", force: :cascade do |t|
    t.integer "author_id"
    t.string "comments", limit: 1024, default: ""
    t.integer "page_id", null: false
    t.text "text"
    t.datetime "updated_on", precision: nil, null: false
    t.integer "version", null: false
    t.index ["author_id"], name: "index_wiki_contents_on_author_id"
    t.index ["page_id"], name: "wiki_contents_page_id"
  end

  create_table "wiki_pages", force: :cascade do |t|
    t.datetime "created_on", precision: nil, null: false
    t.integer "parent_id"
    t.boolean "protected", default: false, null: false
    t.string "title", limit: 255, null: false
    t.integer "wiki_id", null: false
    t.index ["parent_id"], name: "index_wiki_pages_on_parent_id"
    t.index ["wiki_id", "title"], name: "wiki_pages_wiki_id_title"
    t.index ["wiki_id"], name: "index_wiki_pages_on_wiki_id"
  end

  create_table "wiki_redirects", force: :cascade do |t|
    t.datetime "created_on", precision: nil, null: false
    t.string "redirects_to"
    t.integer "redirects_to_wiki_id", null: false
    t.string "title"
    t.integer "wiki_id", null: false
    t.index ["wiki_id", "title"], name: "wiki_redirects_wiki_id_title"
    t.index ["wiki_id"], name: "index_wiki_redirects_on_wiki_id"
  end

  create_table "wikis", force: :cascade do |t|
    t.integer "project_id", null: false
    t.string "start_page", limit: 255, null: false
    t.integer "status", default: 1, null: false
    t.index ["project_id"], name: "wikis_project_id"
  end

  create_table "workflows", force: :cascade do |t|
    t.boolean "assignee", default: false, null: false
    t.boolean "author", default: false, null: false
    t.string "field_name", limit: 30
    t.integer "new_status_id", default: 0, null: false
    t.integer "old_status_id", default: 0, null: false
    t.integer "role_id", default: 0, null: false
    t.string "rule", limit: 30
    t.integer "tracker_id", default: 0, null: false
    t.string "type", limit: 30
    t.index ["new_status_id"], name: "index_workflows_on_new_status_id"
    t.index ["old_status_id"], name: "index_workflows_on_old_status_id"
    t.index ["role_id", "tracker_id", "old_status_id"], name: "wkfs_role_tracker_old_status"
    t.index ["role_id"], name: "index_workflows_on_role_id"
    t.index ["tracker_id"], name: "index_workflows_on_tracker_id"
  end

  add_foreign_key "oauth_access_grants", "oauth_applications", column: "application_id"
  add_foreign_key "oauth_access_grants", "users", column: "resource_owner_id"
  add_foreign_key "oauth_access_tokens", "oauth_applications", column: "application_id"
  add_foreign_key "oauth_access_tokens", "users", column: "resource_owner_id"
end
