[![Build Status](https://travis-ci.org/flandera/redistest.svg?branch=master)](https://travis-ci.org/flandera/redistest)

Redis test app
=============

This is a simple app for testing saving of games to Redis DB.

Installation
------------
Run:
docker-compose up

Testing
----------------
visit localhost:8085 for homepage

storing test games:
http://localhost:8085/game/storegame?game_id=100&user_id=10&score=1000

top 10 gamers by score:
http://localhost:8085/game/gettopgamers

