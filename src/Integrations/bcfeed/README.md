Provides a list of BrightCove videos using account and secret credentials

## To Do

Code to extract the hotlinks to the MP4 files is incomplete. Currently the command just outputs to terminal a list of recent videos from the BC account. This is a workaround for BC feeds not working anymore / BC integrations not importing videos.

## Usage

1. Clone or unzip on a Linux machine with PHP 7 and composer installed.

2. Run composer update on the root folder of the script to get the dependencies

3. Create a ~/.bcfeed/config.json file with this data:

`{
   	"limit": "10",
   	"tags": "xxxx",
   	"proxyURL": "http://localhost:8889/bcProxy.php",
   	"referer": "https://amara.org/",
   	"accountID": "xxxx",
   	"clientID": "xxxx",
   	"clientSecret": "xxxx",
   	"bcPlayer": "http://link.brightcove.com/services/player/bcpidxxxx?bckey=xxxx",
   	"playerID": "xxxx"
   }
` 

accountID, clientID and clientSecret credentials are available on the settings / accounts page when the BrightCove account has been authorized on Amara.

tags should be the tag used by the client to filter the videos to import

bcPlayer value is the normal BC sharing link from the client without the video ID    

playerID can be given by the client or opening the a link.brightcove.com link from the client and checking the network log, e.g. https://xxxx-a.akamaihd.net/pd/2385340575001/ or pubId=2385340575001 - the last number is the player ID. 

4. Run php -S localhost:8889 on the Public folder (for the proxy) 

5. Run bin/bcimport e.g. php bcimport or chmod +x bcimport; ./bcimport