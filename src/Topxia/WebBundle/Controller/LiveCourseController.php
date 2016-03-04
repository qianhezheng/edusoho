<?php
namespace Topxia\WebBundle\Controller;

use Topxia\Common\Paginator;
use Topxia\Common\ArrayToolkit;
use Topxia\Service\Util\EdusohoLiveClient;
use Symfony\Component\HttpFoundation\Request;
use Topxia\Service\CloudPlatform\CloudAPIFactory;

class LiveCourseController extends BaseController
{
    public function liveCapacityAction(Request $request, $id)
    {
        $course = $this->getCourseService()->tryManageCourse($id);

        $client       = new EdusohoLiveClient();
        $liveCapacity = $client->getCapacity();

        return $this->createJsonResponse($liveCapacity);
    }

    public function exploreAction(Request $request)
    {
        if (!$this->setting('course.live_course_enabled')) {
            return $this->createMessageResponse('info', '直播频道已关闭');
        }

        $recenntLessonsCondition = array(
            'status'             => 'published',
            'endTimeGreaterThan' => time()
        );

        $paginator = new Paginator(
            $this->get('request'),
            $this->getCourseService()->searchLessonCount($recenntLessonsCondition)
            , 30
        );

        $recentlessons = $this->getCourseService()->searchLessons(
            $recenntLessonsCondition,
            array('startTime', 'ASC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $courses = $this->getCourseService()->findCoursesByIds(ArrayToolkit::column($recentlessons, 'courseId'));

        $recentCourses = array();

        foreach ($recentlessons as $lesson) {
            $course = $courses[$lesson['courseId']];

            if ($course['status'] != 'published' || $course['parentId'] != '0') {
                continue;
            }

            $course['lesson'] = $lesson;
            $recentCourses[]  = $course;
        }

        $liveCourses = $this->getCourseService()->searchCourses(array(
            'status'   => 'published',
            'type'     => 'live',
            'parentId' => '0'
        ), 'lastest', 0, 10);

        $userIds = array();

        foreach ($liveCourses as $course) {
            $userIds = array_merge($userIds, $course['teacherIds']);
        }

        $users   = $this->getUserService()->findUsersByIds($userIds);
        $default = $this->getSettingService()->get('default', array());
        return $this->render('TopxiaWebBundle:LiveCourse:index.html.twig', array(
            'recentCourses' => $recentCourses,
            'liveCourses'   => $liveCourses,
            'users'         => $users,
            'paginator'     => $paginator,
            'default'       => $default
        ));
    }

    public function liveTabAction()
    {
        $courses = $this->getCourseService()->searchCourses(array(
            'type'     => 'live',
            'status'   => 'published',
            'parentId' => 0,
            'locked'   => 0
        ), array('createdTime', 'DESC'), 0, PHP_INT_MAX);
        $courseIds = ArrayToolkit::column($courses, 'id');

        $lessonsDate = $this->getCourseService()->findLiveLessonsByDate($courseIds, 4);

        $currentLiveLessons = $this->getCourseService()->searchLessons(array(
            'startTimeLessThan'  => time(),
            'endTimeGreaterThan' => time(),
            'type'               => 'live',
            'courseIds'          => $courseIds,
            'status'             => 'published'
        ), array('startTime', 'ASC'), 0, PHP_INT_MAX);

        $futureLiveLessons = $this->getCourseService()->searchLessons(array(
            'startTimeGreaterThan' => time(),
            'endTimeLessThan'      => strtotime(date('Y-m-d').' 23:59:59'),
            'type'                 => 'live',
            'courseIds'            => $courseIds,
            'status'               => 'published'
        ), array('startTime', 'ASC'), 0, PHP_INT_MAX);

        $liveTabs['today']['current'] = $currentLiveLessons;
        $liveTabs['today']['future']  = $futureLiveLessons;

        $dateTabs = array('today');
        $today    = date("Y-m-d");

        foreach ($lessonsDate as $key => &$value) {
            if ($today == $value['date']) {
                continue;
            } else {
                $dayLessons = $futureLiveLessons = $this->getCourseService()->searchLessons(array(
                    'startTimeGreaterThan' => strtotime($value['date']),
                    'endTimeLessThan'      => strtotime($value['date'].' 23:59:59'),
                    'type'                 => 'live',
                    'courseIds'            => $courseIds,
                    'status'               => 'published'
                ), array('startTime', 'ASC'), 0, PHP_INT_MAX);

                $date                      = date('m-d', strtotime($value['date']));
                $liveTabs[$date]['future'] = $dayLessons;
                $dateTabs[]                = $date;
            }
        }

        return $this->render('TopxiaWebBundle:LiveCourse:live-tab.html.twig', array(
            'liveTabs' => $liveTabs,
            'dateTabs' => $dateTabs
        ));
    }

    public function replayListAction()
    {
        $liveReplayList = $this->getCourseService()->searchLessons(array(
            'endTimeLessThan' => time(),
            'type'            => 'live',
            'copyId'          => 0,
            'status'          => 'published'
        ), array('startTime', 'DESC'), 0, 10);

        return $this->render('TopxiaWebBundle:LiveCourse:live-replay-list.html.twig', array(
            'liveReplayList' => $liveReplayList
        ));
    }

    public function liveCourseListAction(Request $request)
    {
        if ($request->query->get('categoryId') || $request->query->get('vipCategoryId')) {
            list($liveCourses, $paginator) = $this->_searchLiveCourseByConditions($request);
        } else {
            list($liveCourses, $paginator) = $this->_searchLiveCourseNormal($request);
        }

        print_r($liveCourses);

        return $this->render('TopxiaWebBundle:LiveCourse:live-course-all-list.html.twig', array(
            'liveCourses' => $liveCourses,
            'paginator'   => $paginator
        ));
    }

    public function ratingCoursesBlockAction()
    {
        $conditions = array(
            'status'            => 'published',
            'type'              => 'live',
            'parentId'          => '0',
            'ratingGreaterThan' => 0.01
        );

        $courses = $this->getCourseService()->searchCourses($conditions, 'Rating', 0, 10);

        return $this->render('TopxiaWebBundle:LiveCourse:rating-courses-block.html.twig', array(
            'courses' => $courses
        ));
    }

    public function coursesBlockAction($courses, $view = 'list', $mode = 'default')
    {
        $userIds = array();

        foreach ($courses as $course) {
            $userIds = array_merge($userIds, empty($course['teacherIds']) ? array() : $course['teacherIds']);
        }

        $users = $this->getUserService()->findUsersByIds($userIds);

        foreach ($courses as &$course) {
            if (empty($course['id'])) {
                $course = array();
            }
        }

        $courses = array_filter($courses);

        return $this->render("TopxiaWebBundle:Course:courses-block-{$view}.html.twig", array(
            'courses' => $courses,
            'users'   => $users,
            'mode'    => $mode
        ));
    }

    public function getClassroomUrlAction(Request $request, $courseId, $lessonId)
    {
        $user = $this->getCurrentUser();

        if (!$user->isLogin()) {
            throw $this->createAccessDeniedException('尚未登入！');
        }

        $lesson = $this->getCourseService()->getCourseLesson($courseId, $lessonId);

        if (empty($lesson)) {
            throw $this->createAccessDeniedException('课时不存在！');
        }

        if (empty($lesson['mediaId'])) {
            throw $this->createAccessDeniedException('直播教室不存在！');
        }

        if ($lesson['startTime'] - time() > 7200) {
            throw $this->createAccessDeniedException('直播还没开始!');
        }

        if ($lesson['endTime'] < time()) {
            throw $this->createAccessDeniedException('直播已结束!');
        }

        $params = array(
            'liveId'   => $lesson['mediaId'],
            'provider' => $lesson['liveProvider'],
            'user'     => $user['email'],
            'nickname' => $user['nickname']
        );

        if ($this->getCourseService()->isCourseTeacher($courseId, $user['id'])) {
            $params['role'] = 'teacher';
        } elseif ($this->getCourseService()->isCourseStudent($courseId, $user['id'])) {
            $params['role'] = 'student';
        } else {
            throw $this->createAccessDeniedException('您不是课程学员，不能参加直播！');
        }

        $client = new EdusohoLiveClient();
        $result = $client->getRoomUrl($params);

        if (empty($result) || isset($result['error'])) {
            return $this->createJsonResponse($result);
        }

        return $this->createJsonResponse(array(
            'url'   => $result['url'],
            'param' => isset($result['param']) ? $result['param'] : null
        ));
    }

    public function entryAction(Request $request, $courseId, $lessonId)
    {
        $user = $this->getCurrentUser();

        if (!$user->isLogin()) {
            return $this->createMessageResponse('info', '你好像忘了登录哦？', null, 3000, $this->generateUrl('login'));
        }

        $lesson = $this->getCourseService()->getCourseLesson($courseId, $lessonId);

        if (empty($lesson)) {
            return $this->createMessageResponse('info', '课时不存在！');
        }

        if (empty($lesson['mediaId'])) {
            return $this->createMessageResponse('info', '直播教室不存在！');
        }

        if ($lesson['startTime'] - time() > 7200) {
            return $this->createMessageResponse('info', '直播还没开始!');
        }

        if ($lesson['endTime'] < time()) {
            return $this->createMessageResponse('info', '直播已结束!');
        }

        $params = array();

        if ($this->getCourseService()->isCourseTeacher($courseId, $user['id'])) {
            $teachers = $this->getCourseService()->findCourseTeachers($courseId);
            $teacher  = array_shift($teachers);

            if ($teacher['userId'] == $user['id']) {
                $params['role'] = 'teacher';
            } else {
                $params['role'] = 'speaker';
            }
        } elseif ($this->getCourseService()->isCourseStudent($courseId, $user['id'])) {
            $params['role'] = 'student';
        } else {
            return $this->createMessageResponse('info', '您不是课程学员，不能参加直播！');
        }

        $liveAccount = CloudAPIFactory::create('leaf')->get('/me/liveaccount');

        $params['id']       = $user['id'];
        $params['nickname'] = $user['nickname'];
        return $this->forward('TopxiaWebBundle:Liveroom:_entry', array('id' => $lesson['mediaId']), $params);

        $params['liveId']   = $lesson['mediaId'];
        $params['provider'] = $lesson['liveProvider'];
        $params['user']     = $user['email'];
        $params['nickname'] = $user['nickname'];

        $client = new EdusohoLiveClient();
        $result = $client->entryLive($params);

        if (empty($result) || isset($result['error'])) {
            return $this->createMessageResponse('info', $result['errorMsg']);
        }

        return $this->render("TopxiaWebBundle:LiveCourse:classroom.html.twig", array(
            'courseId' => $courseId,
            'lessonId' => $lessonId,
            'lesson'   => $lesson,
            'url'      => $this->generateUrl('live_classroom_url', array(
                'courseId' => $courseId,
                'lessonId' => $lessonId
            ))
        ));
    }

    public function verifyAction(Request $request)
    {
        $result = array(
            "code" => "0",
            "msg"  => "ok"
        );

        return $this->createJsonResponse($result);
    }

    protected function makeSign($string)
    {
        $secret = $this->container->getParameter('secret');
        return md5($string.$secret);
    }

    public function replayCreateAction(Request $request, $courseId, $lessonId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $resultList = $this->getCourseService()->generateLessonReplay($courseId, $lessonId);

        if (array_key_exists("error", $resultList)) {
            return $this->createJsonResponse($resultList);
        }

        $lesson          = $this->getCourseService()->getCourseLesson($courseId, $lessonId);
        $lesson["isEnd"] = intval(time() - $lesson["endTime"]) > 0;

        $client = new EdusohoLiveClient();

        if ($lesson['type'] == 'live') {
            $result = $client->getMaxOnline($lesson['mediaId']);
            $this->getCourseService()->setCourseLessonMaxOnlineNum($lesson['id'], $result['onLineNum']);
        }

        return $this->render('TopxiaWebBundle:LiveCourseReplayManage:list-item.html.twig', array(
            'course' => $this->getCourseService()->getCourse($courseId),
            'lesson' => $lesson
        ));
    }

    public function entryReplayAction(Request $request, $courseId, $lessonId, $courseLessonReplayId)
    {
        $course = $this->getCourseService()->tryTakeCourse($courseId);
        $lesson = $this->getCourseService()->getCourseLesson($courseId, $lessonId);

        return $this->render("TopxiaWebBundle:LiveCourse:classroom.html.twig", array(
            'lesson' => $lesson,
            'url'    => $this->generateUrl('live_classroom_replay_url', array(
                'courseId'             => $courseId,
                'lessonId'             => $lessonId,
                'courseLessonReplayId' => $courseLessonReplayId
            ))
        ));
    }

    public function getReplayUrlAction(Request $request, $courseId, $lessonId, $courseLessonReplayId)
    {
        $course = $this->getCourseService()->tryTakeCourse($courseId);
        $result = $this->getCourseService()->entryReplay($lessonId, $courseLessonReplayId);

        return $this->createJsonResponse(array(
            'url'   => $result['url'],
            'param' => isset($result['param']) ? $result['param'] : null
        ));
    }

    public function replayManageAction(Request $request, $id)
    {
        $course      = $this->getCourseService()->tryManageCourse($id);
        $courseItems = $this->getCourseService()->getCourseItems($course['id']);

        foreach ($courseItems as $key => $item) {
            if ($item["itemType"] == "lesson") {
                $item["isEnd"]     = intval(time() - $item["endTime"]) > 0;
                $courseItems[$key] = $item;
            }
        }

        $default = $this->getSettingService()->get('default', array());
        return $this->render('TopxiaWebBundle:LiveCourseReplayManage:index.html.twig', array(
            'course'  => $course,
            'items'   => $courseItems,
            'default' => $default
        ));
    }

    protected function getRootCategory($categoryTree, $category)
    {
        $start = false;

        foreach (array_reverse($categoryTree) as $treeCategory) {
            if ($treeCategory['id'] == $category['id']) {
                $start = true;
            }

            if ($start && $treeCategory['depth'] == 1) {
                return $treeCategory;
            }
        }

        return null;
    }

    protected function getSubCategories($categoryTree, $rootCategory)
    {
        $categories = array();

        $start = false;

        foreach ($categoryTree as $treeCategory) {
            if ($start && ($treeCategory['depth'] == 1) && ($treeCategory['id'] != $rootCategory['id'])) {
                break;
            }

            if ($treeCategory['id'] == $rootCategory['id']) {
                $start = true;
            }

            if ($start == true) {
                $categories[] = $treeCategory;
            }
        }

        return $categories;
    }

    private function _searchLiveCourseNormal($request)
    {
        $courses = $this->getCourseService()->searchCourses(array(
            'status'   => 'published',
            'type'     => 'live',
            'parentId' => 0
        ), array('createdTime', 'DESC'), 0, PHP_INT_MAX);

        $courseIds = ArrayToolkit::column($courses, 'id');

        $conditions = array(
            'status'    => 'published',
            'type'      => 'live',
            'courseIds' => $courseIds
        );

        $paginator = new Paginator(
            $this->get('request'),
            $this->getCourseService()->searchLessonCount($conditions)
            , 10
        );

        $liveLessons = $this->getCourseService()->findRecentLiveLessons($courseIds, $paginator->getOffsetCount(), $paginator->getPerPageCount());
        print_r($liveLessons);exit;

        $liveCourses = $this->getCourseService()->searchCourses(array(
            'status'    => 'published',
            'type'      => 'live',
            'parentId'  => '0',
            'courseIds' => ArrayToolkit::column($liveLessons, 'courseId')
        ), 'lastest', 0, 10);

        print_r($liveLessons);

        // foreach ($liveCourses as $key => $val) {
        // }

        return array($liveCourses, $paginator);
    }

    private function _searchLiveCourseByConditions(Request $request)
    {
        $conditions = array(
            'status'   => 'published',
            'type'     => 'live',
            'parentId' => 0
        );

        if (!empty($request->query->get('categoryId'))) {
            $conditions['categoryId'] = $request->query->get('categoryId');
        }

        if (!empty($request->query->get('vipCategoryId'))) {
            $conditions['vipLevelIdGreaterThan'] = 0;
        }
    }

    protected function getCourseService()
    {
        return $this->getServiceKernel()->createService('Course.CourseService');
    }

    protected function getCategoryService()
    {
        return $this->getServiceKernel()->createService('Taxonomy.CategoryService');
    }

    protected function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }
}
