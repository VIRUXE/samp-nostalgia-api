# Scavenge Nostalgia API

## Overview

This is a general purpose API created for a gameserver called _Scavenge Nostalgia_, which is a fork of the _Scavenge and Survive_ [gamemode](https://github.com/Southclaws/ScavengeSurvive), created by _[Southclaws](https://github.com/Southclaws)_, for _SA-MP_ (now [open.mp](https://open.mp))

The gameserver was terminated months ago.

## About

It's written entirely in PHP, using the Slim micro framework.

Hosted together with the gameserver, it was able to access its accounts SQLite database. And also used its own SQLite database, mainly for communication with the [launcher/anti-cheat](https://github.com/VIRUXE/samp-nostalgia-launcher).

Handled the manifest and downloads for the launcher. When accessed via the web it would display a page to download the launcher.

Verified file integrity received by the launcher

Provided single file downloads of the original game files (mostly for when files needed replacing, for whatever reason)

Handled player's gpci hashes

Gathered hardware ids to apply hardware bans.

News communication directly to the launcher

Player's could login with their accounts directly from the launcher, instead of in-game

It was basically the "judge" that decided if a player was in good standing of joining the server, before they even launched the game, just by validating data received from the launcher.
