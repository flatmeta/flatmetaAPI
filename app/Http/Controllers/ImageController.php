<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class ImageController extends Controller
{
    //

    public function BoxImages(Request $request){

       
        try{ 
            $Images = Image::all();

                foreach($Images as $key => $image){
                    $data['images'][$key]['name']    = $image->name;
                    $data['images'][$key]['country'] = $image->country;
                    $data['images'][$key]['url']     = env('APP_URL').'assets/uploads/defaultimages/'.$image->name; 
                }
               
                return response()->json(['status' => true, 'data' => $data]);

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateBoxImage(Request $request){

        try{ 
            $image = $request->image;

            $UserBoxes = UserBoxes::where('order_id',$request->order_id)->get();
            if(!empty($UserBoxes)){
                foreach($UserBoxes as $UserBox){
                    $updateBox = UserBoxes::findorFail($UserBox->id);
                    $updateBox->image  = $image;
                    $updateBox->save();
                }

                $data['message'] = "Image Updated Successfully.";
                return response()->json(['status' => true, 'data' => $data]);
                
            }else{
                $data['message'] = "Something went wrong.";
                return response()->json(['status' => false, 'data' => $data]);
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UploadBoxesImage(Request $request){

        try{
            
            $userdt = $request->user();
            
            $allowedfileExtension = ['svg','jpg','JPG','jpeg','png','gif','mp4','mov','MOV'];
            $file = $request->file('images');

            $filename = 'fm_image'.date('Ymdhis').'_'.$file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $media_type = explode('/',$file->getMimeType());

            $check = in_array($extension, $allowedfileExtension);

            if ($check){
                
                $upload = $file->move('assets/uploads/defaultimages/',$filename); 
                
                $data['name'] = $filename;
                $data['url']  = env('APP_URL').$upload; 
                
                return response()->json(['status' => true, 'data' => $data]);

            }else{
                $data['message'] = "Unsupported Media Type";
                return response()->json(['status' => false, 'data' => $data]);
            }
           
        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    public function AddNewImages(){
       
        $Images = json_decode(file_get_contents('assets/countries.json'));

        foreach($Images as $key => $image){
            $flag = New Image;
            $flag->name = strtolower($key).'.svg';
            $flag->country = ucwords($image);
            $flag->save();
        }
        
    }
    
}
