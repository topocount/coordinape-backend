<?php


namespace App\Repositories;

use App\Helper\Utils;
use App\Models\Profile;
use App\Models\Teammate;
use App\Models\User;
use App\Notifications\AddNewUser;
use App\Notifications\OptOutEpoch;
use App\Models\Circle;
use DB;
use App\Models\Nominee;

class UserRepository
{
    protected $model;
    protected $profileModel;
    protected $nomineeModel;
    public function __construct(User $model, Profile $profileModel, Nominee $nomineeModel) {
        $this->model = $model;
        $this->profileModel = $profileModel;
        $this->nomineeModel = $nomineeModel;
    }

    public function getUser($address) {
        return $this->model->with(['teammates','pendingSentGifts','profile'])
                ->byAddress($address)->first();
    }

    public function getUser2($address, $circle_id) {
        $query = $this->model->with(['teammates','pendingSentGifts','profile'])->byAddress($address);
        if($circle_id)
            $query->where('circle_id',$circle_id);
        $user = $query->first();
        if(!$user)
            $user =  $this->model->with(['teammates','pendingSentGifts','profile'])->byAddress($address)->where('admin_view', 1)->first();

        return $user;
    }

    public function getUsers($request, $circle_id) {
        $data = $request->all();
        $users = !empty($data['protocol_id']) ? $this->model->with(['profile'])->protocolFilter($data) :
                                                $this->model->with(['profile'])->filter($data);
        if($circle_id)
            $users->where('circle_id',$circle_id);

        if(!empty($data['deleted_users']) && $data['deleted_users'])
            $users->withTrashed();
        return $users->get();
    }

    public function createUser($request, $circle_id) {
        $data = $request->only('address','name','starting_tokens','non_giver','circle_id',
                                'give_token_remaining','fixed_non_receiver', 'role');
        if($data['fixed_non_receiver'] ==1 ) {
            $data['non_receiver'] = 1;
        }
        $circle = Circle::find($circle_id);
        $data['non_receiver'] = $data['fixed_non_receiver'] == 1 || $circle->default_opt_in == 0 ? 1:0;
        $data['address'] =  strtolower($data['address']);
        $data['circle_id'] =  $circle_id;
        $user = $this->model->create($data);
        $nominee = $this->nomineeModel->where('circle_id',$circle_id)->where('address', $data['address'])->where('ended',0)->first();
        if($nominee) {
            $nominee->update(['ended' => 1, 'user_id' => $user->id]);
        }
        if(!$this->profileModel->where('address' , $data['address'])->exists()) {
            $this->profileModel->create(['address' => $data['address']]);
        }
        $user->circle->notify(new AddNewUser($request->admin_user, $user));
        $user->refresh();
        return $user;
    }

    public function deleteUser($user) {
        $pendingGifts = $user->pendingReceivedGifts;
        $pendingGifts->load(['sender.pendingSentGifts']);
        $existingGifts = $user->pendingSentGifts()->with('recipient')->get();

        return DB::transaction(function () use ($user, $pendingGifts, $existingGifts) {
            foreach($existingGifts as $existingGift) {
                $rUser = $existingGift->recipient;
                $existingGift->delete();
                $rUser->give_token_received = $rUser->pendingReceivedGifts()->get()->SUM('tokens');
                $rUser->save();
            }
            foreach($pendingGifts as $gift) {
                $sender = $gift->sender;
                $gift_token = $gift->tokens;
                $gift->delete();
                $token_used = $sender->pendingSentGifts->SUM('tokens') - $gift_token;
                $sender->give_token_remaining = $sender->starting_tokens-$token_used;
                $sender->save();
            }

            Teammate::where('team_mate_id', $user->id)->delete();
            Teammate::where('user_id', $user->id)->delete();
            $user->delete();
            return $user;
        },2);
    }

    public function updateUserData($user, $updateData = []) {

        return DB::transaction(function () use ($user, $updateData) {
            $optOutStr = "";
            $circle = $user->circle;

            if( (!empty($updateData['fixed_non_receiver']) && $updateData['fixed_non_receiver'] != $user->fixed_non_receiver && $updateData['fixed_non_receiver'] == 1) ||
                (!empty($updateData['non_receiver']) && $updateData['non_receiver'] != $user->non_receiver && $updateData['non_receiver'] == 1)
            )
            {
                $pendingGifts = $user->pendingReceivedGifts;
                $pendingGifts->load(['sender.pendingSentGifts']);
                $totalRefunded = 0;
                foreach($pendingGifts as $gift) {
                    if(!$gift->tokens && $gift->note)
                        continue;

                    $sender = $gift->sender;
                    $gift_token = $gift->tokens;
                    $totalRefunded += $gift_token;
                    $senderName = Utils::cleanStr($sender->name);
                    $optOutStr .= "$senderName: $gift_token\n";
                    $gift->delete();
                    $token_used = $sender->pendingSentGifts->SUM('tokens') - $gift_token;
                    $sender->give_token_remaining = $sender->starting_tokens-$token_used;
                    $sender->save();
                }
                $updateData['give_token_received'] = 0;
                if($circle->telegram_id)
                {
                    $circle->notify(new OptOutEpoch($user,$totalRefunded, $optOutStr));
                }
            }

            if($user->non_giver == 0 && !empty($updateData['non_giver']) && $updateData['non_giver'] ==1) {
                $pendingSentGifts = $user->pendingSentGifts;
                foreach($pendingSentGifts as $gift) {
                    if(!$gift->tokens && $gift->note)
                        continue;

                    $recipient = $gift->recipient;
                    $gift->delete();
                    $recipient->give_token_received = $recipient->pendingReceivedGifts->SUM('tokens');
                    $recipient->save();
                }
                $updateData['give_token_remaining'] = $user->starting_tokens;

            }

            $user->update($updateData);
            $nominee = $this->nomineeModel->where('circle_id',$circle->id)->where('address', $user->address)->where('ended',0)->first();
            if($nominee) {
                $nominee->update(['ended' => 1, 'user_id' => $user->id]);
            }
            if(!$this->profileModel::byAddress($user->address)->exists()) {
                $this->profileModel->create(['address' => $user->address]);
            }
            return $user;
        });
    }
}
