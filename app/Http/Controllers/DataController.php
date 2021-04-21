<?php

namespace App\Http\Controllers;

use App\Models\Circle;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Models\PendingTokenGift;
use App\Http\Requests\CircleRequest;
use App\Http\Requests\UserRequest;
use App\Http\Requests\GiftRequest;
use DB;
use App\Models\TokenGift;
use App\Repositories\EpochRepository;
use App\Http\Requests\CsvRequest;
use App\Http\Requests\TeammatesRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use App\Http\Requests\FileUploadRequest;
use App\Helper\Utils;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminUserRequest;
use App\Models\Epoch;
use Carbon\Carbon;
use App\Models\Protocol;
use App\Http\Requests\EpochRequest;
use App\Http\Requests\DeleteEpochRequest;
use App\Models\Teammate;

class DataController extends Controller
{
    protected $repo ;

    public function __construct(EpochRepository $repo)
    {
        $this->repo = $repo;
    }

    public function getCircles(Request $request, $subdomain = null): JsonResponse
    {
        return response()->json(Circle::filter($request->all())->with('protocol')->get());
    }

    public function getProtocols(Request $request, $subdomain = null): JsonResponse
    {
        return response()->json(Protocol::all());
    }

    public function createCircle(CircleRequest $request)
    {
        $circle = new Circle($request->all());
        $circle->save();
        return response()->json($circle);
    }

    public function updateCircle(Circle $circle, CircleRequest $request, $subdomain=null): JsonResponse
    {
        $circle->update($request->all());
        return response()->json($circle);
    }

    public function getUser($address): JsonResponse {
        $user = User::byAddress($address)->first();
        if(!$user)
            return response()->json(['error'=> 'Address not found'],422);

        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function getUser2($subdomain, $address): JsonResponse {
        $circle_id = Utils::getCircleIdByName($subdomain);
        $user = User::byAddress($address);
        if($subdomain)
            $user->where('circle_id',$circle_id);
        $user = $user->first();
        if(!$user)
            return response()->json(['error'=> 'Address not found'],422);

        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function getUsers(Request $request, $subdomain = null): JsonResponse {
        $circle_id = Utils::getCircleIdByName($subdomain);
        $data = $request->all();

        $users = !empty($data['protocol_id']) ? User::protocolFilter($data) : User::filter($data);
        if($subdomain)
            $users->where('circle_id',$circle_id);

        $users = $users->get();
        return response()->json($users);
    }

    public function createUser(AdminCreateUserRequest $request, $subdomain): JsonResponse {
        $data = $request->all();
        $data['address'] =  strtolower($data['address']);
        $user = new User($data);
        $user->save();
        return response()->json($user);
    }

    public function updateUser(UserRequest $request, $subdomain, $address): JsonResponse
    {
        $user = $request->user;
        if(!$user)
            return response()->json(['error'=> 'Address not found'],422);

        $data = $request->all();
        $data = $data['data'];
        $data['address'] =  strtolower($data['address']);
        $user = $this->repo->removeAllPendingGiftsReceived($user, $data);
        return response()->json($user);
    }

    public function adminUpdateUser(AdminUserRequest $request, $circle_id, $address): JsonResponse
    {
        $user = $request->user;
        if(!$user)
            return response()->json(['error'=> 'Address not found'],422);

        $data = $request->all();
        $data = $data['data'];
        $data['address'] =  strtolower($data['address']);
        $user = $this->repo->removeAllPendingGiftsReceived($user, $data);
        return response()->json($user);
    }

    public function updateGifts(GiftRequest $request, $subdomain, $address): JsonResponse
    {
        $user = $request->user;
        $gifts = $request->gifts;
        $addresses = [];

        foreach($gifts as $gift) {
            $addresses[] = strtolower($gift['recipient_address']);
        }

        $users = User::where('circle_id',$request->circle_id)->where('is_hidden',0)->whereIn(DB::raw('lower(address)'),$addresses)->get()->keyBy('address');
        $pendingSentGiftsMap = $user->pendingSentGifts()->get()->keyBy('recipient_id');
        DB::transaction(function () use ($users, $user, $gifts, $address, $pendingSentGiftsMap) {
            $token_used = 0;
            $toKeep = [];
            foreach ($gifts as $gift) {
                $recipient_address = strtolower($gift['recipient_address']);
                if ($users->has($recipient_address)) {
                    if ($user->id == $users[$recipient_address]->id)
                        continue;

                    $gift['sender_id'] = $user->id;
                    $gift['sender_address'] = strtolower($address);
                    $gift['recipient_address'] = $recipient_address;
                    $gift['recipient_id'] = $users[$recipient_address]->id;

                    $token_used += $gift['tokens'];
                    $pendingGift = $pendingSentGiftsMap->has($gift['recipient_id']) ? $pendingSentGiftsMap[$gift['recipient_id']] : null  ;

                    if ($pendingGift) {
                        if ($gift['tokens'] == 0 && $gift['note'] == '') {
                            $pendingGift->delete();

                        } else {
                            $pendingGift->tokens = $gift['tokens'];
                            $pendingGift->note = $gift['note'];
                            $pendingGift->save();
                        }
                    } else {
                        if ($gift['tokens'] == 0 && $gift['note'] == '')
                            continue;

                        $pendingGift = $user->pendingSentGifts()->create($gift);
                    }

                    $toKeep[] = $pendingGift->recipient_id;
                    $users[$recipient_address]->give_token_received = $users[$recipient_address]->pendingReceivedGifts()->get()->SUM('tokens');
                    $users[$recipient_address]->save();
                }
            }

            $this->repo->resetGifts($user, $toKeep);
        },2);

        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function getPendingGifts(Request $request, $subdomain = null): JsonResponse {
        $filters = $request->all();
        if($subdomain) {
            $circle_id = Utils::getCircleIdByName($subdomain);
            if($circle_id) {
                $filters['circle_id'] = $circle_id;
            }
            else {
               return response()->json([]);
            }
        }

        return response()->json(PendingTokenGift::filter($filters)->get());
    }

    public function getGifts(Request $request, $circle_id = null): JsonResponse {
        $filters = $request->all();
        if($circle_id) {
            $filters['circle_id'] = $circle_id;
        }

        return response()->json( Utils::queryCache($request,function () use($filters,$request) {
            return TokenGift::filter($filters)->limit(20000)->get();
        }, 10, $circle_id));
    }

    public function updateTeammates(TeammatesRequest $request, $subdomain=null) : JsonResponse {

        $user = $request->user;
        $teammates = $request->teammates;
        $circle_teammates = User::where('circle_id', $request->circle_id)->where('is_hidden',0)->whereIn('id',$teammates)->pluck('id');
        DB::transaction(function () use ($circle_teammates, $user) {
            $this->repo->resetGifts($user, $circle_teammates);
            if ($circle_teammates) {
                $user->teammates()->sync($circle_teammates);
            }
        });
        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function generateCsv(CsvRequest $request, $subdomain = null)
    {
        $circle_id = Utils::getCircleIdByName($subdomain);
        if (!$circle_id) {
            if (!$request->circle_id)
                return response()->json(['error' => 'Circle not Found'], 422);
            $circle_id = $request->circle_id;
        }

        $epoch = null;
        if($request->epoch_id) {
            $epoch = Epoch::where('circle_id',$circle_id)->where('id',$request->epoch_id )->first();

        } else if ($request->epoch) {
            $epoch = Epoch::where('circle_id',$circle_id)->where('number', $request->epoch)->first();
        }
        if(!$epoch)
            return 'Epoch Not found';

        return $this->repo->getEpochCsv($epoch, $circle_id, $request->grant);
    }

    public function uploadAvatar(FileUploadRequest $request, $subdomain=null) : JsonResponse {

        $file = $request->file('file');
        $resized = Image::make($request->file('file'))
            ->resize(100, null, function ($constraint) { $constraint->aspectRatio(); } )
            ->encode($file->getCLientOriginalExtension(),80);
        $new_file_name = Str::slug(pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME)).'_'.time().'.'.$file->getCLientOriginalExtension();
        $ret = Storage::put($new_file_name, $resized);
        if($ret) {
            $user = User::byAddress($request->get('address'))->where('circle_id',$request->circle_id)->first();
            if($user->avatar && Storage::exists($user->avatar)) {
                Storage::delete($user->avatar);
            }

            $user->avatar = $new_file_name;
            $user->save();
            return response()->json($user);
        }

        return response()->json(['error' => 'File Upload Failed' ,422]);
//        dd(Storage::disk('s3')->allFiles(''));
    }

    public function epoches(Request $request, $subdomain) : JsonResponse  {
        $circle_id = Utils::getCircleIdByName($subdomain);
        if (!$circle_id) {
            return response()->json(['error' => 'Circle not Found'], 422);
        }
        $epoches = Epoch::where('circle_id', $circle_id);
        if($request->current) {
            $today = Carbon::today()->toDateString();
            $epoches->whereDate('start_date', '<=', $today)->whereDate('end_date','>=', $today);
        }
        $epoches = $epoches->get();
        return response()->json($epoches);
    }

    public function createEpoch(EpochRequest $request, $subdomain) : JsonResponse  {
        $data = $request->all();
        $circle_id = $request->circle_id;
        $exist = Epoch::where('circle_id',$circle_id)->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
        if($exist)  {
            $error = ValidationException::withMessages([
                'start_date' => ['Has overlapping date with existing epoch'],
                'end_date' => ['Has overlapping date with existing epoch'],
            ]);
            throw $error;
        }
        $data['circle_id'] = $circle_id;
        $epoch = new Epoch($data);
        $epoch->save();
        return response()->json($epoch);
    }

    public function deleteEpoch(DeleteEpochRequest $request, $circle_id, Epoch $epoch) : JsonResponse {
        $today = Carbon::today();
        if($epoch->circle_id != $circle_id) {
            $error = ValidationException::withMessages([
                'epoch' => ['You are not authorized to delete this epoch'],
            ]);
            throw $error;
        }
        else if ($epoch->start_date <= $today || $epoch->ended == 1) {
            $error = ValidationException::withMessages([
                'epoch' => ['You cannot delete an epoch that has started or ended'],
            ]);
            throw $error;
        }

        $epoch->delete();

        return response()->json($epoch);
    }

    public function deleteUser(AdminUserRequest $request, $circle_id, $address) : JsonResponse  {

        $user = User::byAddress($address)->where('circle_id',$circle_id)->first();
        //$user = $request->user;
        $ret = $this->repo->removeAllPendingGiftsReceived($user);
        if(is_null($ret)) {
            $error = ValidationException::withMessages([
                'failed' => ['Delete user failed please try again'],
            ]);
            throw $error;
        }
        $data = DB::transaction(function () use($user) {
            Teammate::where('team_mate_id', $user->id)->delete();
            $user->teammates()->delete();
            $user->delete();

            return response()->json($user);

        });

        if(is_null($data)) {
            $error = ValidationException::withMessages([
                'failed' => ['Delete user failed please try again'],
            ]);
            throw $error;
        } else {
            return $data;
        }
    }
}
