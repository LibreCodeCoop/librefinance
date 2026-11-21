# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
@api @shard1
Feature: Financial spaces enforce authorization
  Scenario: Owner can see the company ledger
    Given I am finance test user "lfmaintestA"
    When I create an isolated finance space
    Then the HTTP status must be 201
    When I list accounts for the current finance space
    Then the HTTP status must be 200
    And the account list must contain 4 accounts

  Scenario: Another user must not see balances from an unshared space
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I switch to finance test user "lfmaintestB"
    And I list accounts for the current finance space
    Then the HTTP status must be 404

  Scenario: Viewer cannot create expense postings
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    And I grant "lfmaintestB" the "viewer" role for this finance space
    When I switch to finance test user "lfmaintestB"
    And I post a synthetic expense of 300 minor units
    Then the HTTP status must be 403
