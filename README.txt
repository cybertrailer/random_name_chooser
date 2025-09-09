Random Name Chooser
================

## Description

The Random Name Chooser module takes a list of name and randomly matches each name with another name in the list. Participants 
can go to a page and view their match.

## Requirements

None

## Installation

The Random Name Chooser project installs like any other Drupal module. There is extensive documentation on how to do this on
   [Installing Modules](https://www.drupal.org/docs/extending-drupal/installing-modules).

## Configuration

After enabling the module go to the settings page (/admin/config/content/random_name_chooser) and toggle enable spouse letter on or off, select 
the maximum number of name in the list, enter a description that participants will see and note the link to send to participants.

Access setting, view name and add names using the Random Name tab in your profile. 

Random Name Chooser has an option to assign the same single letter to a couple so that they won't be assigned to each other.

Enter the names for the list. Each name needs a personal password (plain text) that they must enter before they can see their 
match. This makes the process private as others won't be able see their match.

Once the names have been added send a note to the particiapants, with their personal password and link to the selection page (see 
settings). Participants select their name from the list and enter their password to view their match.

The matches are all created when the first person selects their match. Admins can view the matches at (/random-name-chooser/results).

Note: Once the matches have been assigned, adding or removing a new name will clear the match list.

Random Name Chooser can be used by any site user by providing permissions to a role. Without permissions set, only one list 
can be created by the site administrator.