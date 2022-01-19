# v2b import for Amara

Downloads video projects from box.com account, uploads them to a restricted access server and posts them to Amara.

## Operative System Requirements

Currently the script is built to run on Linux. It's tested on Ubuntu. Instructions below include Windows for future reference, but the script currently looks for folders on a Linux install.

## Script setup

### PHP

Check if PHP is installed in your system.

From the command line, type

`php --version`

To install it for Windows get the "VC15 x64 Thread Safe" Zip from here

https://www.php.net/downloads.php

On Ubuntu (to make sure you have the latest PHP version):

`sudo apt install -y software-properties-common`

`sudo add-apt-repository ppa:ondrej/php`

`sudo apt update`

`sudo apt-get install php7.4-cli php7.4-fpm php7.4-bcmath php7.4-curl php7.4-gd php7.4-intl php7.4-json php7.4-mbstring php7.4-mysql php7.4-opcache php7.4-sqlite3 php7.4-xml php7.4-zip`

### Composer

Composer helps manage PHP libraries.

Install composer:

https://getcomposer.org/

On Linux, use `sudo apt install composer`

On Windows, make a folder for Composer (e.g. in Program files), navigate to it from the command line and use these four commands:

`php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"`

`php -r "if (hash_file('sha384', 'composer-setup.php') === 'c5b9b6d368201a9db6f74e2611495f369991b72d9c8cbd3ffbc63edff210eb73d46ffbfce88669ad33695ef77dc76976') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"`

`php composer-setup.php`

`php -r "unlink('composer-setup.php');"`

### Script set up

Download the script folder from github and unzip on a home folder.

Navigate to the main folder (the one that contains this README.md) from a command line.

Type `composer update` -- this will import the extra libraries needed. They are listed as "require" in composer.json

Create a .v2bimport folder in the home folder, and a config.json file in it. Fill in the values as needed:

videosFolder is the folder where new videos will be downloaded to.

`{
    "v2bImport": {
        "videosFolder": "/home/fran/AmaraV2B/" 
    },
    "AmaraAPI": {
        "root": "https://amara.org/api/",
        "username": "xxxx",
        "key": "xxxx",
        "version": ""
    }
}`

Leave "version" blank.

### rclone setup

Make sure you have the latest version so it includes Box. Ubuntu may ship with an older one.

https://rclone.org/downloads/

Note: In cases where rclone was already configured on a machine and you want to port the configuration to another, just run `rclone config file` to find where the config file is and copy it. Usually it's in ~/.config/rclone. Run `rclone listremotes` to confirm it's copied correctly. Run `rclone config` to edit the sources skip questions then answer yes when it asks to refresh the token (it needs a refreshed one for the new machine). 

#### box.com

For the box.com account:

https://rclone.org/box/

Box setup requires a browser for the Oauth authorization (for a headless server config check rclone documentation).

Name the box account v2b-box in the configuration steps.

Listing root files to test the config:

`rclone ls v2b-box:/`

The box token will expire after 60 days not used, instructions to refresh it are here: https://rclone.org/box/

#### Google Cloud Storage

https://rclone.org/googlecloudstorage/

Same choices as the doc page except if the bucket has no Uniform bucket level access (v2b one should have, Google's AoD bucket for WMT videos doesn't), then it has to be defined in the Access Control List for new objects step:

* Select 1 / Object owner gets OWNER access, and all Authenticated Users get READER access for requiring users to be logged into an authorized Google account
* Select 6 / Object owner gets OWNER access, and all Users get READER access for the typical public links, doesn't require being logged into Google

Use 6 by default until 1 is tested.

See:

https://cloud.google.com/storage/docs/uniform-bucket-level-access

TL;DR

* IAM is for bucket and project-wide permissions, also outside the GCS service itself
* ACL allows per-object permissions too, but only GCS

Listing root folders:

`rclone lsd google-gcs:bucket-name`

GCS doesn't have real folders, so rclone mkdir won't actually work. To create an empty folder, create it locally, add some placeholder file (if the folder is empty, it won't be created either) and then sync it up

```
mkdir myFolder
touch myFolder/empty.md
rclone sync myFolder google-gcs:bucket-name/myFolder
```

#### Drive

The Google Drive storage from gcs@amara.org is used to store the transcripts. 

Run rclone config to set it up. You need the access credentials for gcs@amara.org.

New remote name should be v2b-transcripts.

Click the link on "Making your own client_id" and follow the instructions.

If you have trouble entering the Google API console, edit the link below with the authuser= number for the order in which you logged into gcs@amara.org (starts at 0), or log out of all other accounts.

https://console.developers.google.com/apis/dashboard?project=v2b-intake&authuser=2

Set v2b-intake as App name, and again in the Desktop app Name screen

Back to rclone config, set scope to 1

Don't set the root folder.

Skip the rest.

Test with:

`rclone ls --include *.docx v2b-box:amara`

## Running the script

Run crontab -e to schedule the script. For example, to run it every hour:

`00 * * * * cd /home/fran/git/v2bimport/src && ./v2bimport.php > /dev/null 2>&1`

Adjust the path to the script as needed.

The script logs information to $home/.v2bimport/logs/, including the count of videos imported. The logs rotate every day, it'll keep 365 max.

Video URLs that were found already once on Amara are logged on known_videos.csv. These will be skipped forever on, so the script can get to the new videos faster and doesn't do too many API requests.