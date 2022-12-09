@extends('layouts.app')
@section('title')
    Create Admin User | MyMotion
@stop
@section('content')

    <div class="card">
        <div class="card-header">
            <div class="row flex-between-end">
                <div class="col-auto align-self-center">
                    <h5 class="mb-0">Add Admin User</h5>
                </div>
            </div>
        </div>
        <div class="card-body bg-light">
            <div class="tab-content">
                <div class="tab-pane preview-tab-pane active" role="tabpanel">
                    <form class="row g-3" method="POST" action="{{ route('StoreAdminUser') }}">
                        {{ csrf_field() }}
                        <input class="form-control" name="id" type="hidden" value="{{ !empty($user) ? $user->id : '' }}">

                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="text-danger fw-700">*</span> </label>
                            <input class="form-control" name="first_name" type="text" placeholder="First Name"
                                value="{{ !empty($user) ? $user->first_name : '' }}" required>
                            @error('first_name')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input class="form-control" name="last_name" type="text" placeholder="Last Name"
                                value="{{ !empty($user) ? $user->last_name : '' }}" required />
                            @error('last_name')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input class="form-control" name="email" type="email" placeholder="abc@xyz.com"
                                value="{{ !empty($user) ? $user->email : '' }}" required />
                            @error('email')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input class="form-control" minlength="6" name="password" type="text"
                                {{ empty($user) ? 'required' : '' }} />
                            @error('password')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>


                        @if(!empty($user) && $user->role == "0")
                            <input  name="password" type="hidden" value="0" />
                        @else
                            <div class="col-md-6">
                                <label class="form-label">Role </label>
                                <select class="form-select" name="role" required>
                                    <option {{ !empty($user) && $user->role == '1' ? 'selected' : '' }} value="1">Admin
                                    </option>
                                    <option {{ !empty($user) && $user->role == '2' ? 'selected' : '' }} value="2">Physio
                                    </option>
                                </select>
                                @error('role')
                                    <strong>{{ $message }}</strong>
                                @enderror
                            </div>
                        @endif

                        <div class="col-md-6">
                            <label class="form-label">Status </label>
                            <select class="form-select" name="status" required>
                                <option {{ !empty($user) && $user->status == '1' ? 'selected' : '' }} value="1"> Active </option>
                                <option {{ !empty($user) && $user->status == '2' ? 'selected' : '' }} value="2"> Inactive </option>
                            </select>
                            @error('role')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>

                        <div class="col-12 text-end">
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>

@endsection

@section('script')
    <script>
        var options = {
            valueNames: ["id", "name", "status"]
        };

        var userList = new List('customersTable', options);
    </script>
@endsection
