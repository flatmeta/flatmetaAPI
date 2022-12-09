@extends('layouts.app')
@section('title')
  Tiles
@stop
@section('content')


<div class="card mb-3" id="customersTable" data-list='{"valueNames":["id","company_name","fullname","email","role"],"page":10,"pagination":true}'>
    <div class="card-header">
      <div class="row flex-between-center">
        <div class="col-4 col-sm-auto d-flex align-items-center pe-0">
          <h5 class="fs-0 mb-0 text-nowrap py-2 py-xl-0 me-3">{{ "Subscriptions" }}</h5>
         
        </div>
        <div class="col-8 col-sm-auto text-end ps-2">
          <div id="table-customers-replace-element">
            
          </div>
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped fs--1 mb-0 overflow-hidden">
          <thead class="bg-200 text-900">
            <tr>
              <th class="pe-1 align-middle white-space-nowrap" >ID</th>
              <th class="pe-1 align-middle white-space-nowrap" >latitute</th>
              <th class="pe-1 align-middle white-space-nowrap" >longitute</th>
              <th class="pe-1 align-middle white-space-nowrap" >Image</th>
              <th class="pe-1 align-middle white-space-nowrap" >Price</th>
            </tr>
          </thead>
          <tbody class="list" id="table-customers-body">
            @foreach($tiles as $key => $user)
            <tr class="btn-reveal-trigger">
              <td class="id align-middle white-space-nowrap py-2">
                {{ $user->id }}
              </td>
              <td class="first_name align-middle white-space-nowrap py-2">
                 {{ $user->lat }}
              </td>
              <td class="last_name align-middle white-space-nowrap py-2">
                {{ $user->lng }}

               
              </td>
              <td class="status align-middle white-space-nowrap py-2">
                <img src="https://api.flatmeta.io/assets/uploads/defaultimages/{{ $user->image }}" width="20"/>
              </td>
              <td class="created_at align-middle white-space-nowrap py-2">
                {{ $user->price }}
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    
  </div>

@endsection

@section('script')
    <script>
      var options = {
        valueNames: ["id","first_name","last_name","email","created_at"]
      };

      var userList = new List('customersTable', options);    
  </script>
@endsection
