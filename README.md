# silentc
Source code of the bot t.me/silentcbot on Telegram.

# About the bot
[SilentC](https://t.me/silentcbot) is a Telegram bot launched in the beginning of 2018. It's intended to help channels owners to manage the posts of their channels. SilentC delete unwanted posts using the rules you define (rules based in the post time, type and content). You can even use regular expressions (RegEx) and Twig templates to create advanced filters.

# About the code
I won't lie: i actually dislike it. It's ok about indentation and style, but it's in PHP (don't be offended, i'm just angry and sad with PHP).

Well i don't dislike the code only due to its language (if i did, it would be a kinda futile reason), there's a list of simple things that you should know:
* I didn't use prepared statements in whole code. There's shameful string interpolations in almost all queries. At least i use PDO.
* I hate the fact that the tables names are 'user' and 'channel' (in singular). I wish i had used 'users' and 'channels' as i actually do for all my new bots.
* The whole code for handling the update is inside a function. Ok, this sounded good cause i'm able to run both in webhook and polling without big changes in the code. The reason i dislike it it's cause it's entirely in one file.
* You won't find nice comments. Don't search for it.

Some people might say "Hey, but it's your own code! How can you hate it?". I don't know who would say that but i can answer.

See, i started learning PHP at the beginning of December, 2017 (i started before with Python, in April, 2017). SilentC initially was just a bot for praticing, but the beta bot became a bit "famous" in just some days.

I was starting with PHP. I had a lot of bad pratices that actually i don't have anymore (the biggest one is to use PHP kek). I started to develop SilentC 2 years ago and after i rewrited it 2 times, but now it became too big and i'm tired of rewriting in PHP.

## "Why are you publishing the source now?"
As i said i dislike the code and i got angry with PHP. I decided some important things that will definitely make the SilentC's future better. One of them is to make the bot open source.

# Requirements
* Unix-like OS
* PHP 7+ (i recommend to use the newer versions before 7.4)

- The awesome (but in PHP) library [MadelineProto](https://github.com/danog/MadelineProto), but it is downloaded and updated automatically. You don't have to care with it.
- The included file `twig.phar` that is just the package of [Twig](https://twig.symfony.com) packed with [comphar](https://github.com/mpyw/comphar).

# Cloning
1. Put the files on your server
If you have SSH access, simply clone the repository with git:
`git clone https://github.com/usernein/silentc`
If you don't have SSH access, you can [download the repository as zip](https://github.com/usernein/silentc/archive/master.zip) and upload it to your server. Then unzip it.

2. Edit config.php
The file `config.php` is where you will define the sudoers ids (sudoers can use special but dangerous commands, like /sql that execute SQL queries).
Simply replace 276145711 (my Telegram id) to your Telegram id.
Also replace YOUR:TOKEN_HERE to your bot token.

3. Run the bot
If you are in cli, run `php poll.php`. If you will use webhooks, set the webhook url to `webhook.php`.

Also if you are in cli and will run in polling, i highly recommend you to use tmux or screen to keep it running 24/7. Also run `bash poll.sh` instead of `php poll.php`. It will restart the bot if any fatal error occurs.

# Contact
[I](https://t.me/usernein) have a [channel](https://t.me/hpxlist) and a [group](https://t.me/silentcchat).

Ah, and i'll not maintain this code. Keep tuned hehe