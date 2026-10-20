<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * يطابق RegisterController::universities() + RegisterController::universityHierarchy()
 * القديمين — بيانات مرجعية بدون Middleware لفورم التسجيل.
 */
class UniversitiesController extends Controller
{
    /** GET /api/v1/auth/universities */
    public function index(Request $request)
    {
        $locale = $request->query('locale', 'en');
        $nameCol = $locale === 'ar' ? 'official_name_ar' : 'official_name_en';

        // الجامعة المحذوفة (صفها أو حساب مالكها) مينفعش تظهر في التسجيل.
        $data = \App\Models\University::applyListable(DB::table('universities'))
            ->select('id', "{$nameCol} as name")
            ->orderBy($nameCol)
            ->get();

        return $this->apiSuccess($data, 'Universities retrieved successfully.');
    }

    /** GET /api/v1/auth/university-hierarchy/{id} */
    public function hierarchy(Request $request, int $id)
    {
        $locale = $request->query('locale', 'ar');
        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        $faculties = DB::table('faculties')
            ->where('university_id', $id)
            ->where('status', 'active')
            ->select('id', "{$nameCol} as name")
            ->get();

        $facultyIds = $faculties->pluck('id');

        $departments = DB::table('departments')
            ->whereNull('deleted_at')
            ->whereIn('faculty_id', $facultyIds)
            ->where('status', 'active')
            ->select('id', 'faculty_id', "{$nameCol} as name")
            ->get();

        return response()->json([
            'faculties'   => $faculties,
            'departments' => $departments,
        ]);
    }
}
