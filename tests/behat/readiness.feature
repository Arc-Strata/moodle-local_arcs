@local @local_arcs
Feature: Arcs connector readiness checks
  In order to know whether Arcs can read this site
  As an admin
  I need the system status report to check the Arcs connector's set-up

  Background:
    Given the following config values are set as admin:
      | config               | value | plugin  |
      | enablewebservices    | 0     |         |
      | webserviceprotocols  |       |         |
      | enabled_stores       |       | tool_log |

  # From Moodle 5.3 the status report loads each check's result with JavaScript.
  @javascript
  Scenario: The checks fail on a site that is not set up, then pass once it is
    When I log in as "admin"
    And I navigate to "Reports > System status" in site administration
    Then I should see "Error" in the "Arcs web services" "table_row"
    And I should see "Error" in the "Arcs REST protocol" "table_row"
    And I should see "Error" in the "Arcs connector service" "table_row"
    And I should see "Error" in the "Arcs token" "table_row"
    And I should see "There is no token for the Arcs connector service." in the "Arcs token" "table_row"
    And I should see "Warning" in the "Arcs log events" "table_row"
    And I should see "Info" in the "Arcs change log" "table_row"

    Given the following config values are set as admin:
      | config               | value             | plugin   |
      | enablewebservices    | 1                 |          |
      | webserviceprotocols  | rest              |          |
      | enabled_stores       | logstore_standard | tool_log |
    And the following "users" exist:
      | username | firstname | lastname |
      | arcs     | Arcs      | Service  |
    And the following "roles" exist:
      | shortname  | name        | archetype |
      | arcsexport | Arcs export |           |
    And the following "role capability" exists:
      | role                | arcsexport |
      | local/arcs:export   | allow      |
      | webservice/rest:use | allow      |
    And the following "system role assigns" exist:
      | user | role       |
      | arcs | arcsexport |
    And the following "core_webservice > Tokens" exist:
      | user | service    |
      | arcs | local_arcs |
    And I navigate to "Reports > System status" in site administration
    And I should see "None of the tokens for the Arcs connector service can be used (1 found)." in the "Arcs token" "table_row"
    And I click on "Edit external service" "link" in the "Arcs connector service" "table_row"
    And I set the field "Enabled" to "1"
    And I press "Save changes"
    And I navigate to "Server > Web services > External services" in site administration
    And I click on "Authorised users" "link" in the "Arcs connector" "table_row"
    And I set the field "Not authorised users" to "Arcs Service"
    And I press "Add"
    And I navigate to "Reports > System status" in site administration
    Then I should see "OK" in the "Arcs web services" "table_row"
    And I should see "OK" in the "Arcs REST protocol" "table_row"
    And I should see "OK" in the "Arcs connector service" "table_row"
    And I should see "Tokens Arcs can use: 1." in the "Arcs token" "table_row"
    And I should see "OK" in the "Arcs log events" "table_row"
