# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
@api @shard1
Feature: Nextcloud MCP client has read-only company-scoped tools
  Scenario: Tools are discoverable and read only
    Given I am finance test user "lfmaintestA"
    When I request the MCP financial tool list
    Then the HTTP status must be 200
    And every MCP tool must be declared read-only

  Scenario: MCP cannot expose an unrelated company
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I switch to finance test user "lfmaintestB"
    And I request MCP accounts for the current finance space
    Then the MCP tool must deny access

  Scenario: MCP write tools are not registered
    Given I am finance test user "lfmaintestA"
    And I create an isolated finance space
    When I call an unregistered MCP write tool
    Then the MCP request must be rejected as invalid parameters
