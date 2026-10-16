<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\Orders;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    /**
     * Returns the order when it belongs to the authenticated user, otherwise null.
     */
    protected function findOwnedOrder(Request $request)
    {
        $order = Orders::find($request->order_id);
        if (!$order || (int) $order->user_id !== (int) $request->user()->id) {
            return null;
        }
        return $order;
    }

    /** Squares of the order the request targets: all of them, or the `tiles` [{lat,lng}] subset. */
    protected function targetBoxes(Request $request, Orders $order)
    {
        $boxes = UserBoxes::where('order_id', $order->id)->get();
        $tiles = $request->input('tiles');
        if (!is_array($tiles) || empty($tiles)) {
            return $boxes;
        }
        return $boxes->filter(function ($box) use ($tiles) {
            foreach ($tiles as $t) {
                if (isset($t['lat'], $t['lng'])
                    && abs((float) $box->lat - (float) $t['lat']) < 0.000002
                    && abs((float) $box->lng - (float) $t['lng']) < 0.000002) {
                    return true;
                }
            }
            return false;
        })->values();
    }

    /** {minGx,minGy,maxGx,maxGy} as integers, or null. */
    protected function cleanSpan($span): ?string
    {
        if (is_string($span)) {
            $span = json_decode($span, true);
        }
        if (!is_array($span)) {
            return null;
        }
        $keys = ['minGx', 'minGy', 'maxGx', 'maxGy'];
        $out = [];
        foreach ($keys as $k) {
            if (!isset($span[$k]) || !is_numeric($span[$k])) {
                return null;
            }
            $out[$k] = (int) $span[$k];
        }
        if ($out['maxGx'] < $out['minGx'] || $out['maxGy'] < $out['minGy']) {
            return null;
        }
        // An image can't be stretched over more than 64x64 squares.
        if ($out['maxGx'] - $out['minGx'] > 63 || $out['maxGy'] - $out['minGy'] > 63) {
            return null;
        }
        return json_encode($out);
    }

    /**
     * Sets one image on the owner's selected squares. With `span_bounds` the image
     * is stretched across that block so it reads as one big picture.
     */
    public function UpdateBoxImage(Request $request)
    {
        try {
            $order = $this->findOwnedOrder($request);
            if (!$order) {
                return response()->json(['status' => false, 'data' => ['message' => 'Unauthorized to edit this land area.']]);
            }
            $image = trim((string) $request->image);
            if (!preg_match('#^https?://\S+$#i', $image) || strlen($image) > 2048) {
                return response()->json(['status' => false, 'data' => ['message' => 'The image must be an http(s) link.']]);
            }

            $boxes = $this->targetBoxes($request, $order);
            if ($boxes->isEmpty()) {
                return response()->json(['status' => false, 'data' => ['message' => 'No matching squares in this land area.']]);
            }
            $span = $boxes->count() > 1 ? $this->cleanSpan($request->input('span_bounds')) : null;

            foreach ($boxes as $box) {
                if (!empty($box->image) && $box->image !== $image) {
                    $box->previous_image = $box->image;
                }
                $box->image = $image;
                $box->span_bounds = $span;
                $box->save();
            }

            return response()->json(['status' => true, 'data' => ['message' => 'Image updated.', 'updated' => $boxes->count()]]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Puts the selected squares back to their previous image (or the plain map). */
    public function RevertBoxImage(Request $request)
    {
        try {
            $order = $this->findOwnedOrder($request);
            if (!$order) {
                return response()->json(['status' => false, 'message' => 'Unauthorized to edit this land area.']);
            }
            $boxes = $this->targetBoxes($request, $order);
            if ($boxes->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'Order tiles not found']);
            }
            foreach ($boxes as $box) {
                $box->image = $box->previous_image ?: null;
                $box->previous_image = null;
                $box->span_bounds = null;
                $box->save();
            }
            return response()->json(['status' => true, 'data' => ['message' => 'Land artwork reverted.', 'reverted' => $boxes->count()]]);
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

            // No SVG (it can carry script and is served from the API origin). Images up to 5 MB, videos up to 20 MB.
            $allowedfileExtension = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov'];
            $file = $request->file('images');
            // Extension comes from the detected content type, never from the client file name.
            $extension = $file->guessExtension();
            $maxKb = in_array($extension, ['mp4', 'mov'], true) ? 20480 : 5120;
            $validator = Validator::make($request->all(), ['images' => 'file|max:' . $maxKb . '|mimes:' . implode(',', $allowedfileExtension)]);

            if (!$validator->fails() && in_array($extension, $allowedfileExtension, true)) {
                $filename = 'fm_image' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
                $uploadDir = 'assets/uploads/defaultimages/';
                if (!file_exists($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $file->move($uploadDir, $filename);

                $data['name'] = $filename;
                $data['url'] = $this->baseUrl() . $uploadDir . $filename;

                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Unsupported Media Type (JPG, PNG, GIF or WEBP up to 5 MB, MP4 or MOV up to 20 MB)';
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
