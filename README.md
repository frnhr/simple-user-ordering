simple-user-ordering
====================

WP plugin to allow drag&amp;drop ordering of users

## What

This is a plugin for developers. It enables sorting of users in WP admin. 

It won't affect anything on the site other then the user list (table?) in the WP admin.

Sorting is done by adding `menu_order` meta value for every user.

## How

Unfortunately WordPress doesn't have a built-in mechanism to fetch users by meta field, so for now the only available option is to use some semi-intelligent sorting after fetching. See http://wordpress.stackexchange.com/a/31045/5902 for an example.