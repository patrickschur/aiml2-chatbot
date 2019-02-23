aiml2-chatbot
=

[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.1-4AC51C.svg)](http://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

A simple and easy to use AIML 2.0 chatbot in just one file. I created this as a prototype to test and work with AIML 2.0 chatbots without the need of a fullblown AIML engine.
It contains many bad-practices (for example mixing procedural with object-oriented code) so don't bother me.
Don't use it in a production environment. It was just created as a prototype. Maybe it will go above this stage some day, who knows?

Usage
--

Just download the project, upload some AIML 2.0 files to it and execute the `chatbot.php` file in a terminal.

Supported Tags
--

Currently 37 different AIML 2.0 tags out of 40 are supported.

| Tag | Supported? |
| :---: | :---: |
| condition | ✅ |
| sraix | ❎ |
| system | ❎ |
| date | ✅ |
| input | ✅ |
| request | ✅ |
| response | ✅ |
| that | ✅ |
| sentence | ✅ |
| star | ✅ |
| topicstar | ✅ |
| thatstar | ✅ |
| oob | ❎ |
| interval | ✅ |
| sr | ✅ |
| srai | ✅ |
| lowercase | ✅ |
| uppercase | ✅ |
| formal | ✅ |
| explode | ✅ |
| first | ✅ |
| size | ✅ |
| program | ✅ |
| rest | ✅ |
| random | ✅ |
| get | ✅ |
| set | ✅ |
| think | ✅ |
| id | ✅ |
| learn | ✅ |
| learnf | ✅ |
| normalize | ✅ |
| denormalize | ✅ |
| map | ✅ |
| bot | ✅ |
| person | ✅ |
| person2 | ✅ |
| gender | ✅ |
| eval | ✅ |
| loop | ✅ |
