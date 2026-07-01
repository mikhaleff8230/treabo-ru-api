<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Chat channels
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = \Marvel\Database\Models\Conversation::find($id);
    
    if (!$conversation) {
        return false;
    }
    
    // Check if user is part of the conversation
    if ($conversation->user_id === $user->id) {
        return true;
    }
    
    // Check if user is in conversation_user pivot table
    return $conversation->users()->where('user_id', $user->id)->exists();
});

Broadcast::channel('proffi.chat.{chatId}', function ($user, $chatId) {
    $chat = \App\Models\ProffiChat::find($chatId);
    if (!$chat) {
        return false;
    }

    return (int) $chat->customer_id === (int) $user->id
        || (int) $chat->specialist_id === (int) $user->id;
});
