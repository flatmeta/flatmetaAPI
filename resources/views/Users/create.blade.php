@extends('layouts.app')
@section('title')
    {{ (!empty($text)) ? "Edit Texts" : "Create Texts" }} | Flatmeta
@stop
@section('content')

    <div class="card">
        <div class="card-header">
            <div class="row flex-between-end">
                <div class="col-auto align-self-center">
                    <h5 class="mb-0">{{ (!empty($text)) ? "Edit Texts" : "Create Texts" }}</h5>
                </div>
            </div>
        </div>
        <div class="card-body bg-light">
            <div class="tab-content">
                <div class="tab-pane preview-tab-pane active">
                    <form  method="POST" action="{{ route('StoreReportText') }}">
                        <div class="row g-3">
                            {{ csrf_field() }}
                            <input class="form-control" name="id" type="hidden" name="id" value="{{ !empty($text) ? $text->id : '' }}">

                            <div class="col-md-6">
                                <label class="form-label">
                                    Full Name
                                    <span class="text-danger fw-700">*</span>
                                </label>
                                <input class="form-control" name="text" type="text" placeholder="Full  Name" value="{{ !empty($text) ? $text->text : '' }}" required>
                                @error('text')
                                    <strong>{{ $message }}</strong>
                                @enderror
                            </div>
    
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option {{ !empty($user) && $user->status == '1' ? 'selected' : '' }} value="1"
                                        selected="selected">Verified</option>
                                    <option {{ !empty($user) && $user->status == '2' ? 'selected' : '' }} value="2">
                                        Unverified</option>
                                    <option {{ !empty($user) && $user->status == '3' ? 'selected' : '' }} value="3">
                                        Deleted</option>
                                </select>
                                @error('status')
                                    <strong>{{ $message }}</strong>
                                @enderror
                            </div>
                           
                        </div>

                        <div class="col-12 text-end mt-3">
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>

                    </form>
                </div>

            </div>
        </div>
    </div>

@endsection

@section('script')
    <script  >
        var options = {
            valueNames: ["id", "name", "status"]
        };

        var userList = new List('customersTable', options);

        $(".unit_type").on('change',function(){
            // Metric Selected
            if(this.value == 0){
                // Metric Box Selected && Hide Imperial
                $('.Metric').show();
                $('.Imperial').hide();
            }

            // Metric Selected
            if(this.value == 1){
                // Imperial Box Selected && Hide Metric
                $('.Imperial').show();
                $('.Metric').hide();
            }
        });

        

    </script>

    @if(empty($user))
        <script>
            $(document).ready(function(){
                setTimeout(() => {
                    $('.Metric').show();
                    $('.Imperial').hide();
                });
            });
        </script>
    @endif

    @if(!empty($user) && $user->unit_type == "0")
        <script>
            $(document).ready(function(){
                $('.Metric').show();
                $('.Imperial').hide();
            });
        </script>
    @else
        <script>
            $(document).ready(function(){
                $('.Imperial').show();
                $('.Metric').hide();
            });
        </script>
    @endif

@endsection
