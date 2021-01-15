# Github to Azure DevOps migration

The main goal of this project is to migrate Issues and Commit references from Github to Azure DevOps.

Things that was out of scope, but it's still possible to implement:
 - it will not sync issues between, so it's only one time migration
 - it will not create users, all related users must be creat upfront
 - it will not sync/upload attachments to Azure
 - it will not update all internal links like "Some issue #123" to new Azure issue, it is done partly
 - changed/created date missing because of some limitations of Azure api

Every failed try requires wipe on Azure DevOps, see Wipe section.

## Requirements

* PHP7.4 or later
* Composer https://getcomposer.org/

## Usage

1. Clone repo
2. Setup
3. Copy target repository to Azure
3. `php ./bin/github2azure migrate -u usermap.csv`

If it fails, see Wipe section and restart again.

## Setup

Install dependencies:

```shell
composer install
```

```shell
cp .env.dist .env
```

Edit `.env` and fill Github organization/repo and Azure DevOps organization and repo. 

Prepare users list to migrate (put only users that exists in Azure DevOps):

```shell
cp usermap.csv.dist usermap.csv
```

Edit `usermap.csv` and keep the original format: `github-username, Azure User Name <azure@email.com>`.

It is needed because migration of user accounts is out of scope of this migration tool.

Also Github API doesn't allow extracting real user emails, but only username.

## Copy target repository to Azure

```shell
git remote add azure git@ssh.dev.azure.com:v3/[ORGANIZATION]/[PROJECT]/[REPO]
git fetch origin +refs/heads/*:refs/heads/* --prune && git push azure --mirror --force
```

## Wipe

To wipe everything go to Azure DevOps Boards > Queries and create a simple query with all issue. Select all issue with CTRL+A and delete them via right click menu.
Then go to Boards > Work items > Recycle Bin and again select all and click to Permanently delete.

Go to Repos and at the top (breadcrumbs) click to repo name and select Manage repositories and re-create the needed repository (if it's single just rename it to some temp and create new repo with default name again and delete renamed).
