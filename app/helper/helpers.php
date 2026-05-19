<?php


if (!function_exists('tourTransform')) {
    function tourTransform($item): array
    {
        return [
            "id" => $item->id,
            "name" => $item->name,
            "description" => $item->description,
            "period" => $item->period,
            "residence" => $item->residence,
            "weight" => $item->weight,
            "airline" => $item->airline,
            "price" => $item->price,
            "date" => $item->date,
            "coverImage" => $item->cover_image,
            "maxNumber" => $item->max_number,
            "active" => $item->active,
            "featured" => $item->featured,
            "mostRequested" => $item->most_requested,
            "location" => $item->location,
            "googleMap" => $item->google_map,
            "haveDiscount" => $item->have_discount,
            "amountDiscount" => $item->amount_discount,
            "guideName" => $item->user?->name,
            "countryName" => $item->country?->name,
            "cityName" => $item->city?->name,
            "userId" => $item->user_id,
            "countryId" => $item->country_id,
            "cityId" => $item->city_id,
            "partnerId" => $item->partner_id,
            "partner" => partnerBrandTransform($item->partner),
        ];
    }
}

if (!function_exists('guideTransform')) {
    function userTransform($item): array
    {
        return [
            "id" => $item->id,
            "name" => $item->name,
            "email" => $item->email,
            "mobile" => $item->mobile,
            "type" => $item->type,
            "avatar" => $item->avatar,
            "roleId" => $item->role_id,
            "countryId" => $item->country_id,
            "cityId" => $item->city_id,
            "partnerId" => $item->partner_id,
            "roleName" => $item->role?->name,
            "partnerName" => $item->partner?->name,
            "partnerEmail" => $item->partner?->email,
            "partnerPhone" => $item->partner?->phone,
            "partnerLogo" => $item->partner?->logo,
            "partnerStatus" => $item->partner?->status,
            "countryName" => $item->country?->name,
            "cityName" => $item->city?->name,
        ];
    }
}

if (!function_exists('partnerTransform')) {
    function partnerTransform($item): array
    {
        return [
            "id" => $item->id,
            "name" => $item->name,
            "email" => $item->email,
            "phone" => $item->phone,
            "avatar" => $item->logo,
            "logo" => $item->logo,
            "status" => $item->status,
        ];
    }
}

if (!function_exists('partnerBrandTransform')) {
    function partnerBrandTransform($item): ?array
    {
        if (!$item) {
            return null;
        }

        return [
            "id" => $item->id,
            "name" => $item->name,
            "logo" => $item->logo,
        ];
    }
}

if (!function_exists('countryTransform')) {
    function countryTransform($item): array
    {
        return [
            "id" => $item->id,
            "name" => $item->name,
            "description" => $item->description,
            "coverImage" => $item->cover_image,
        ];
    }
}
if (!function_exists('cityTransform')) {
    function cityTransform($item): array
    {
        return [
            "id" => $item->id,
            "name" => $item->name,
            "description" => $item->description,
            "coverImage" => $item->cover_image,
            "status" => $item->status,
        ];
    }
}
if (!function_exists('imageTransform')) {
    function imageTransform($item): array
    {
        return [
            "path" => $item->path,
            "description" => $item->description,
        ];
    }
}

if (!function_exists('placeTransform')) {
    function placeTransform($item): array
    {
        return [
            "placeId" => $item->id,
            "name" => $item->name,
            "address" => $item->address,
            "image" => $item->image,
            "description" => $item->description,
            "locationPath" => $item->location_path,
            "placeTypeId" => $item->placeType?->id,
            "typeName" => $item->placeType?->name,
            "typeIcon" => $item->placeType?->icon,
            "featuredSelections" => $item->featured_selections == 1 ? true : false,
            "status" => $item->status == 1 ? true : false,
            "cityId" => $item->city_id,
            "cityName" => $item->city->name,
            "countryId" => $item->city->country_id,
            "countryName" => $item->city->country->name,
            "partnerId" => $item->partner_id,
            "partner" => partnerBrandTransform($item->partner),
            'categoryPlaces' => $item->categoryPlaces->map(fn($i) => categoryPlacesTransform($i)),
            'featurePlaces' => $item->featurePlaces->map(fn($i) => featurePlacesTransform($i)),
        ];
    }



    if (!function_exists('trackTransform')) {
        function trackTransform($item): array
        {
            return [
                "name" => $item->name,
                "address" => $item->address,
                "latitude" => $item->latitude,
                "longitude" => $item->longitude,
                "date" => $item->date,
                "time" => $item->time,
            ];
        }
    }
    if (!function_exists('containTransform')) {
        function containTransform($item): array
        {
            return [
                "name" => $item->name,
                "icon" => $item->icon,
                "description" => $item->description,
            ];
        }
    }
    if (!function_exists('excludeTransform')) {
        function excludeTransform($item): array
        {
            return [
                "name" => $item->name,
                "icon" => $item->icon,
                "description" => $item->description,
            ];
        }
    }

    if (!function_exists('placeTypeTransform')) {
        function placeTypeTransform($item): array
        {
            return [
                "name" => $item->placeType?->name,
                "slug" => $item->placeType?->slug,
                "title" => $item->placeType?->title,
                "description" => $item->placeType?->description,
            ];
        }
    }


    if (!function_exists('placeTypeTransform2')) {
        function placeTypeTransform2($item): array
        {
            return [
                "name" => $item->name,
                "slug" => $item->slug,
                "title" => $item->title,
                "description" => $item->description,
            ];
        }
    }
    if (!function_exists('categoryPlacesTransform')) {
        function categoryPlacesTransform($item): array
        {
            return [
                "name" => $item->name,
            ];
        }
    }

    if (!function_exists('featurePlacesTransform')) {
        function featurePlacesTransform($item): array
        {
            return [
                "name" => $item->name,
            ];
        }
    }
}
