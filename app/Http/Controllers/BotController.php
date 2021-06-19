<?php

namespace App\Http\Controllers;

use App\Models\PendingTokenGift;
use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Notifications\SendSocialMessage;
use App\Models\Circle;
use DB;

class BotController extends Controller
{

    const yearnCircleId = 1;

    public function webHook(Request $request) {
        $updates = Telegram::getWebhookUpdates();
        $message = $updates->message;
        Log::info($message);
        if($updates && !empty($message) &&
            ((!empty($message['entities'][0]['type']) && ($message['entities'][0]['type']=='bot_command' )) ||
            (!empty($message['text']['entities'][0]['type']) && ($message['text']['entities'][0]['type']=='bot_command' ))
            )
        ) {
            $this->processCommands($message);
        }

       // return response()->json(['success' => 1]);
    }

    private function processCommands($message) {
        $textArray = explode(' ',$message['text']);
        $command = $textArray[0];
        $is_group = $message['chat']['type'] == 'group';
        switch($command) {
            case '/start':
                if(!$is_group) {
                    $users = User::where('telegram_username', $message['from']['username'])->get();
                    if(count($users)==0) {
                        // don't exist
                        return;
                    } else {
                        foreach($users as $user) {
                            $user->chat_id = $message->chat->id;
                            $user->save();
                        }

                        $users[0]->notify(new SendSocialMessage(
                            "Congrats {$users[0]->name} You have successfully registered your Telegram to Coordinape !\nI will occasionally send you important reminders and updates!"
                        ));
                    }
                }
                break;
            case '/give':
                $this->give($message, $is_group);
                break;

            case '/announce':
                $this->sendAnnouncement($message);
                break;

            case '/allocations':
                $this->getAllocs($message, $is_group);
                break;
        }
    }

//    private function processGroupCommands($message) {
//        $textArray = explode(' ',$message['text']);
//        $command = $textArray[0];
//
//        switch($command) {
//            case '/give':
//                $this->give($message, $message['chat']['type'] == 'group');
//                break;
//
//            case '/announce':
//               $this->sendAnnouncement($message);
//             break;
//        }
//    }

    private function give($message, $is_group) {
        // command @username amount note
        $textArray = explode(' ',$message['text']);
        if(count($textArray) < 3)
            return false;

        $recipientUsername = substr($textArray[1],1);
        $amount = filter_var($textArray[2], FILTER_VALIDATE_INT) ? (int)($textArray[2]): 0;
        $note = !empty($textArray[3]) ? $textArray[3]:'';
        $circle = $this->getCircle($message, $is_group);
        Log::info($circle);
        if($circle) {
            $user = User::with('pendingSentGifts')->where('telegram_username', $message['from']['username'])->where('circle_id',$circle->id)->first();
            Log::info($user);
            if($user) {
                $notifyModel = $is_group ? $circle : $user;
                if(count($circle->epoches) == 0)
                {
                    $notifyModel->notify(new SendSocialMessage(
                        "Sorry $user->name ser, there is currently no active epochs"
                    ));
                    return false;
                }
                if($user->non_giver) {
                    $notifyModel->notify(new SendSocialMessage(
                        "Sorry $user->name ser, You are not allowed to give allocations"
                    ));
                    return false;
                }
                $recipientUser = User::where('telegram_username',$recipientUsername)->where('circle_id', $circle->id)->first();
                Log::info($recipientUser);
                if($recipientUser) {
                    $noteOnly = false;
                    $optOutText = "";
                    if($recipientUser->non_receiver || $recipientUser->fixed_non_receiver) {
                        $amount = 0;
                        $optOutText = "(Opt Out)";
                    }
                    if($amount == 0 )
                        $noteOnly = true;

                    DB::transaction(function () use($user, $recipientUser, $circle, $notifyModel, $amount, $note, $noteOnly, $recipientUsername, $optOutText) {
                        $pendingSentGifts = $user->pendingSentGifts;
                        $remainingGives = $user->give_token_remaining;
                        $user->teammates()->syncWithoutDetaching([$recipientUser->id]);
                        foreach($pendingSentGifts as $gift) {
                            if($gift->recipient_id==$recipientUser->id) {
                                if(($remainingGives + $gift->tokens - $amount) < 0) {
                                    $notifyModel->notify(new SendSocialMessage(
                                        "Sorry $user->name ser, You only have $remainingGives tokens remaining you're ngmi"
                                    ));
                                    return false;
                                }
                                $current = $gift->tokens;
                                $gift->tokens = $amount;
                                $gift->note = $note;

                                $gift->save();
                                $recipientUser->give_token_received = $recipientUser->pendingReceivedGifts()->get()->SUM('tokens');
                                $recipientUser->save();
                                $user->give_token_remaining = $user->starting_tokens - $user->pendingSentGifts()->get()->SUM('tokens');
                                $user->save();
                                $notifyModel->notify(new SendSocialMessage(
                                    "$user->name ser, You have successfully updated your allocated $current tokens for $recipientUser->name @$recipientUsername $optOutText to $amount tokens. You have $user->give_token_remaining tokens remaining"
                                ));
                                return true;
                            }
                        }

                        if($amount == 0 && !$note)
                            return false;

                        if($amount > $user->give_token_remaining) {
                            $notifyModel->notify(new SendSocialMessage(
                                "Sorry $user->name ser, You only have $remainingGives tokens remaining you're ngmi"
                            ));
                            return false;
                        }

                        $giftData['sender_id'] = $user->id;
                        $giftData['sender_address'] = $user->address;
                        $giftData['recipient_address'] = $recipientUser->address;
                        $giftData['recipient_id'] = $recipientUser->id;
                        $giftData['tokens'] = $amount;
                        $giftData['circle_id'] = $circle->id;
                        $giftData['note'] = $note;
                        $gift = new PendingTokenGift($giftData);
                        $gift->save();
                        $recipientUser->give_token_received = $recipientUser->pendingReceivedGifts()->get()->SUM('tokens');
                        $recipientUser->save();
                        $user->give_token_remaining = $user->starting_tokens - $user->pendingSentGifts()->get()->SUM('tokens');
                        $user->save();
                        $message = $noteOnly? "You have successfully sent a note to $recipientUser->name $optOutText":"$user->name ser, You have successfully allocated $amount tokens to $recipientUser->name @$recipientUsername. You have $user->give_token_remaining tokens remaining";
                        $notifyModel->notify(new SendSocialMessage(
                            $message
                        ));
                    });

                } else {
                    $notifyModel->notify(new SendSocialMessage(
                        "Sorry $user->name ser, $recipientUsername does not exist in this circle"
                    ));
                }
            }
        }
    }

    private function getCircle($message, $is_group) {
        $whitelisted = [self::yearnCircleId];
        $chat_id = $message['chat']['id'];
        $circle = $is_group ? Circle::with(['epoches' => function ($q) {
            $q->isActiveDate();
        }])->where('telegram_id', $chat_id)->whereIn('id',$whitelisted)->first(): Circle::with(['epoches' => function ($q) {
            $q->isActiveDate();
        }])->whereIn('id',$whitelisted)->first();

        return $circle;
    }

    private function getAllocs($message, $is_group = false) {

        $circle = $this->getCircle($message, $is_group);
        if($circle) {
            $user = User::with('pendingSentGifts.sender')->where('telegram_username', $message['from']['username'])->where('circle_id',$circle->id)->first();
            if($user) {
                $notifyModel = $is_group ? $circle:$user;
                $allocStr = '';
                $pendingSentGifts = $user->pendingSentGifts;
                foreach($pendingSentGifts as $gift) {
                    $allocStr .= "{$gift->sender->name} : $gift->tokens\n";
                }
                if(!$allocStr)
                    $allocStr = "You have no allocations currently";
                $notifyModel->notify(new SendSocialMessage(
                    $allocStr
                ));
            }
        }

    }

    private function sendAnnouncement($message) {
        $annText = substr($message['text'],10);
        $user = User::where('telegram_username', $message['from']['username'])
            ->where(function($q) {
                $q->where('ann_power', 1)->orWhere('super',1);
            })->first();
        if($user) {
            $circles = Circle::whereNotNull('telegram_id')->get();
            foreach($circles as $circle) {
                $circle->notify(new SendSocialMessage(
                    $annText
                ));
            }
        }
    }
}
