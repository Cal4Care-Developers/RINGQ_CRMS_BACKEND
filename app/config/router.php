<?php

$router = $di->getRouter();

// Define your routes here

$router->handle($_SERVER['REQUEST_URI']);

$router->add('/provision/{id}', [
    'controller' => 'index',
    'action'     => 'index',
]);

$router->add('/provision/{id}/{mac}', [
    'controller' => 'index',
    'action'     => 'index',
]);

$router->add('/license', [
    'controller' => 'index',
    'action'     => 'license',
]);

// $router->add('/index/{param}', [
//     'controller' => 'index',
//     'action'     => 'index',
//     'param'      => 1, 
// ]);

// APIs for Chat settings
$router->add('/api/getSettings', [
    'controller' => 'chat',
    'action'     => 'index',
])->setName('chat-settings');

$router->add('/api/listSettings', [
    'controller' => 'chat',
    'action'     => 'list',
])->setName('chat-settings');

$router->add('/api/addSettings', [
    'controller' => 'chat',
    'action'     => 'add',
])->setName('chat-settings');

$router->add('/api/updateSettings', [
    'controller' => 'chat',
    'action'     => 'update',
])->setName('chat-settings');

$router->add('/api/deleteSettings', [
    'controller' => 'chat',
    'action'     => 'delete',
])->setName('chat-settings');

$router->add('/api/getSingleWidget', [
    'controller' => 'chat',
    'action'     => 'getWidget',
])->setName('chat-settings');

// APIs for Chat Bot Messages
$router->add('/api/getChatBotQuestions', [
    'controller' => 'Chatquestion',
    'action'     => 'index',
])->setName('Chat-Bot-Questions');

$router->add('/api/addBotQuest', [
    'controller' => 'Chatquestion',
    'action'     => 'addBotQuest',
])->setName('Chat-Bot-Questions');

$router->add('/api/updateBotQuest', [
    'controller' => 'Chatquestion',
    'action'     => 'updateBotQuest',
])->setName('Chat-Bot-Questions');

$router->add('/api/deleteBotQuest', [
    'controller' => 'Chatquestion',
    'action'     => 'deleteBotQuest',
])->setName('Chat-Bot-Questions');

// APIs for Chat Bot Greetings
$router->add('/api/getChatBotGreetings', [
    'controller' => 'QChatbotGreetings',
    'action'     => 'index',
])->setName('Chat-Bot-Greetings');

$router->add('/api/addBotGreets', [
    'controller' => 'QChatbotGreetings',
    'action'     => 'addBotGreets',
])->setName('Chat-Bot-Greetings');

$router->add('/api/updateBotGreets', [
    'controller' => 'QChatbotGreetings',
    'action'     => 'updateBotGreets',
])->setName('Chat-Bot-Greetings');

$router->add('/api/deleteBotGreets', [
    'controller' => 'QChatbotGreetings',
    'action'     => 'deleteBotGreets',
])->setName('Chat-Bot-Greetings');

// APIs for Chat Bot Keywords
$router->add('/api/getCBKeywords', [
    'controller' => 'QChatCloseKeywords',
    'action'     => 'index',
])->setName('Chat-Bot-Greetings');

$router->add('/api/addCBKeywords', [
    'controller' => 'QChatCloseKeywords',
    'action'     => 'addCBKeywords',
])->setName('Chat-Bot-Greetings');

$router->add('/api/updateCBKeywords', [
    'controller' => 'QChatCloseKeywords',
    'action'     => 'updateCBKeywords',
])->setName('Chat-Bot-Greetings');

// APIs for Facebook
$router->add('/api/messenger', [
    'controller' => 'QFbChatMessages',
    'action'     => 'messenger',
])->setName('Chat-FB');
$router->add('/api/addFbSettings', [
    'controller' => 'QFbChatMessages',
    'action'     => 'add',
])->setName('Chat-FB');

// APIs for SSO settings
$router->add('/api/getSSODetails', [
    'controller' => 'QSsoDetails',
    'action'     => 'getMsSso',
])->setName('get-Ms-sso-login');
$router->add('/api/loginMsSso', [
    'controller' => 'QSsoDetails',
    'action'     => 'loginMsSso',
])->setName('ms-sso');
$router->add('/api/callbackMsSso', [
    'controller' => 'QSsoDetails',
    'action'     => 'callbackMsSso',
])->setName('callback-ms-sso');
$router->add('/api/googlesso', [
    'controller' => 'QSsoDetails',
    'action'     => 'googleSSO',
])->setName('google-sso');

// APIs for ChatGPT Knowledge-Base settings
$router->add('/api/getAIdetails', [
    'controller' => 'QChatgptKb',
    'action'     => 'index',
])->setName('get-ai-details');
$router->add('/api/addKBUrls', [
    'controller' => 'QChatgptKb',
    'action'     => 'addKBUrls',
])->setName('add-kb-urls');
$router->add('/api/getPageLinks', [
    'controller' => 'QChatgptKb',
    'action'     => 'getPageLinks',
])->setName('add-kb-urls');
