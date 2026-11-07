@local @local_arcs
Feature: Arcs connector settings
  In order to control what Arcs may read
  As an admin
  I need to set the change log retention and the optional user fields

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name | param2 |
      | text     | team      | Team | 100    |

  Scenario: Set the change log retention and the optional user fields
    When I log in as "admin"
    And I navigate to "Plugins > Local plugins > Arcs connector" in site administration
    Then I should see "system status report"
    And the following fields match these values:
      | Change log retention           | 30 days                                   |
      | Optional user fields to export | Department, Institution, City/town, Country |
    And I set the following fields to these values:
      | Change log retention           | 90 days                              |
      | Optional user fields to export | Department, Phone, Team (custom profile field) |
    And I press "Save changes"
    And the following fields match these values:
      | Change log retention           | 90 days                              |
      | Optional user fields to export | Department, Phone, Team (custom profile field) |
