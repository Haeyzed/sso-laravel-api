<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'phone' => $this->phone,
            'profile_image' => $this->profile_image,
            'email_verified_at' => $this->email_verified_at,
            'device_token' => $this->device_token,
            'last_login_at' => $this->last_login_at,
            'current_login_at' => $this->current_login_at,
            'last_login_ip' => $this->last_login_ip,
            'current_login_ip' => $this->current_login_ip,
            'login_count' => $this->login_count,
            'provider' => $this->provider,
            'provider_id' => $this->provider_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
