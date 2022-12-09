@extends('layouts.app')
@section('title')
  Report Text
@stop
@section('content')


<div class="card mb-3" id="customersTable" data-list='{"valueNames":["id","company_name","fullname","email","role"],"page":10,"pagination":true}'>
    <div class="card-header">
      <div class="row flex-between-center">
        <div class="col-4 col-sm-auto d-flex align-items-center pe-0">
          <h5 class="fs-0 mb-0 text-nowrap py-2 py-xl-0 me-3">{{ "Report Text" }}</h5>
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
              <th class="pe-1 align-middle white-space-nowrap" >Text</th>
              <th class="pe-1 align-middle white-space-nowrap" >Status</th>
              <th class="pe-1 align-middle white-space-nowrap" >Created At</th>
              <th class="align-middle no-sort"></th>
            </tr>
          </thead>
          <tbody class="list" id="table-customers-body">
            @foreach($texts as $key => $text)
            <tr class="btn-reveal-trigger">
              <td class="id align-middle white-space-nowrap py-2">
                {{ $text->id }}
              </td>
              <td class="first_name align-middle white-space-nowrap py-2">
                {{ $text->text }}
              </td>
              
              <td class="status align-middle white-space-nowrap py-2">
                {{ ($text->status == '1') ? 'Active' : "Inactive"; }}
              </td>
              <td class="created_at align-middle white-space-nowrap py-2">
                {{ date_format(date_create($text->created_at), 'd-m-Y') }}
              </td>

              <td class="align-middle white-space-nowrap py-2 text-end">
                <div class="dropdown font-sans-serif position-static">
                  <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" id="customer-dropdown-0" data-bs-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs--1"></span></button>
                  <div class="dropdown-menu dropdown-menu-end border py-0" aria-labelledby="customer-dropdown-0">
                    <div class="bg-white py-2">
                      <a class="dropdown-item text-info" href="{{ route('CreateReportText',$text->id) }}">Edit</a>
                    </div>
                  </div>
                </div>
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
