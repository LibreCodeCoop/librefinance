# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
@api @shard2
Feature: AI requires human consent and cannot modify the ledger
  Scenario: Provider detection is read-only
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I inspect finance AI capabilities
    Then the HTTP status must be 200
    And the AI integration must require consent and block automated writes

  Scenario: Consent is required even without a configured model
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I request finance AI analysis without consent
    Then the HTTP status must be 422
    And the API error must equal "ai_consent_required"

  Scenario: A read-only financial viewer cannot use AI analysis
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    And I grant "lfmaintestB" the "viewer" role for this finance space
    When I switch to finance test user "lfmaintestB"
    And I inspect finance AI capabilities
    Then the HTTP status must be 403
