# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
@api @shard2
Feature: Ledger postings preserve exact money and idempotency
  Scenario: Expense posting creates exactly one journal
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I post a synthetic expense of 730 minor units
    Then the HTTP status must be 201
    When I list journals for the current finance space
    Then the journal list must contain 1 entries

  Scenario: Retrying with the same idempotency key never posts twice
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I post a synthetic expense of 500 minor units
    Then the HTTP status must be 201
    When I repeat the previous synthetic posting
    Then the HTTP status must be 201
    When I list journals for the current finance space
    Then the journal list must contain 1 entries
