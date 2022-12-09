@extends('layouts.app')
@section('title')
    Create Report Text | MyMotion
@stop
@section('content')

    <div class="card">
        <div class="card-header">
            <div class="row flex-between-end">
                <div class="col-auto align-self-center">
                    <h5 class="mb-0">Add Report Text </h5>
                </div>
            </div>
        </div>
        <div class="card-body bg-light">
            <div class="tab-content">
                <div class="tab-pane preview-tab-pane active" role="tabpanel">
                    <form class="row g-3" method="POST" action="{{ route('StoreReportText') }}">
                        {{ csrf_field() }}
                        <input class="form-control" name="id" type="hidden" value="{{ !empty($text) ? $text->id : '' }}">

                        <div class="col-md-6">
                            <label class="form-label">Report Text <span class="text-danger fw-700">*</span> </label>
                            <input class="form-control" name="text" type="text" placeholder="Report Text"
                                value="{{ !empty($text) ? $user->text : '' }}" required>
                            @error('text')
                                <strong>{{ $message }}</strong>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status </label>
                            <select class="form-select" name="status" required>
                                <option {{ !empty($text) && $text->status == '1' ? 'selected' : '' }} value="1"> Active </option>
                                <option {{ !empty($text) && $text->status == '2' ? 'selected' : '' }} value="2"> Inactive </option>
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
