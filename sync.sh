#!/bin/bash
php sync.phar --environment=sync.yml
git add .
git commit -m'update'
git push