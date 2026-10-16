<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\UserBoxes;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    protected function baseUrl(): string
    {
        $base = config('app.url') ?: env('APP_URL', url('/'));
        return rtrim($base, '/') . '/';
    }

    public function BoxImages(Request $request)
    {
        try {
            $data = ['images' => []];
            $images = Image::all();
            $baseUrl = $this->baseUrl();

            foreach ($images as $image) {
                $data['images'][] = [
                    'name' => $image->name,
                    'country' => $image->country,
                    'url' => $baseUrl . 'assets/uploads/defaultimages/' . $image->name,
                ];
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateBoxImage(Request $request)
    {
        try {
            $image = $request->image;

            $UserBoxes = UserBoxes::where('order_id', $request->order_id)->get();
            if ($UserBoxes && $UserBoxes->isNotEmpty()) {
                $firstOldImage = $UserBoxes->first()->image ?? '';
                if (!empty($firstOldImage) && $firstOldImage !== $image) {
                    $order = \App\Models\Orders::find($request->order_id);
                    if ($order) {
                        $meta = is_array(json_decode($order->log ?? '', true)) ? json_decode($order->log, true) : [];
                        $meta['previous_image'] = $firstOldImage;
                        $order->log = json_encode($meta);
                        $order->save();
                    }
                }

                foreach ($UserBoxes as $UserBox) {
                    $updateBox = UserBoxes::findOrFail($UserBox->id);
                    $updateBox->image = $image;
                    $updateBox->save();
                }

                $data['message'] = 'Image Updated Successfully.';
                $data['previous_image'] = $firstOldImage;
                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'No tiles found for the specified order_id.';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Reverts tiles in an order back to default map (clears custom image) or a previous image
     */
    public function RevertBoxImage(Request $request)
    {
        try {
            $previousImage = $request->input('previous_image', null);
            $order = \App\Models\Orders::find($request->order_id);
            if ($previousImage === null && $order && !empty($order->log)) {
                $meta = json_decode($order->log, true);
                if (is_array($meta) && isset($meta['previous_image'])) {
                    $previousImage = $meta['previous_image'];
                }
            }
            $previousImage = $previousImage ?? '';

            $UserBoxes = UserBoxes::where('order_id', $request->order_id)->get();
            if ($UserBoxes && $UserBoxes->isNotEmpty()) {
                foreach ($UserBoxes as $UserBox) {
                    $UserBox->image = $previousImage;
                    $UserBox->save();
                }
                return response()->json([
                    'status' => true,
                    'data' => [
                        'reverted_to' => $previousImage,
                        'message' => 'Land artwork reverted successfully.',
                    ],
                ]);
            }
            return response()->json(['status' => false, 'message' => 'Order tiles not found']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UploadBoxesImage(Request $request)
    {
        try {
            if (!$request->hasFile('images')) {
                return response()->json(['status' => false, 'data' => ['message' => 'No image file provided']]);
            }

            $allowedfileExtension = ['svg', 'jpg', 'JPG', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'MOV'];
            $file = $request->file('images');

            $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $filename = 'fm_image' . date('YmdHis') . '_' . $safeOriginal;
            $extension = strtolower($file->getClientOriginalExtension());

            if (in_array($extension, $allowedfileExtension)) {
                $uploadDir = 'assets/uploads/defaultimages/';
                if (!file_exists($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $file->move($uploadDir, $filename);

                $data['name'] = $filename;
                $data['url'] = $this->baseUrl() . $uploadDir . $filename;

                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Unsupported Media Type';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function AddNewImages()
    {
        $Images = json_decode(file_get_contents('assets/countries.json'));

        foreach ($Images as $key => $image) {
            $flag = new Image();
            $flag->name = strtolower($key) . '.svg';
            $flag->country = ucwords($image);
            $flag->save();
        }
    }
}
