<?php


namespace App\Service\Activity;


use App\Entity\Activity;
use App\Entity\Group;
use App\Entity\User;
use App\Enum\Activity\Type;
use App\Enum\Data\Path;
use App\Service\Data\ApiExchanger;
use App\Service\Storage\Memcached;
use Symfony\Component\Validator\Constraints\DateTime;

class ActivityService
{
    const ACTIVITY_MEMCACHED_KEY = "user_activity_";

    const ACTIVITY_MEMCACHED_TIMEOUT = 86400;

    /**
     * @var ApiExchanger
     */
    private $apiExchanger;
    /**
     * @var Memcached
     */
    private $memcached;

    public function __construct(ApiExchanger $apiExchanger, Memcached $memcached)
    {
        $this->apiExchanger = $apiExchanger;
        $this->memcached = $memcached;
    }

    public function getUserActivities(int $userId, $force = false): ?array
    {
        $activitiesRaw = $this->getActivities($userId, $force);
        if(!$activitiesRaw) {
            return null;
        }

        $activities = [];
        foreach ($activitiesRaw as $activityRaw) {
            if(is_array($activityRaw)) {
                if (isset($activityRaw['userId']) && $activityRaw['userId'] > 0) {
                    $activityRaw['user'] = $activityRaw['userId'];
                }
                $activities[] = (new Activity())->fromArray($activityRaw);
            }
        }

        return $activities;
    }

    private function getActivities(int $userId, $force = false)
    {
        $activities = $this->apiExchanger->getUserActivities($userId, $force);
        if(!$activities) {
            return null;
        }
        $this->checkActivitiesInMemcached($userId, $activities);

        return $activities;
    }

    /**
     * Формат хранения:
     * [
     *  'activities' => [...], - список id (с учётом порядка)
     *  'visible' => false
     * ]
     * В MC хранится инфа по тем объявлениям которые пришли из ApiEx
     * При разнице данных => в поле visible указывается false
     * Поле выставляется в true при посещении страницы пользователя, либо при просмотре плашки сверху
     */
    public function checkActivitiesInMemcached(int $userId, array $activities)
    {
        $activitiesKey = $this->getMemcachedKey($userId);
        $memcachedActivity = $this->memcached->get($activitiesKey);

        if(!$memcachedActivity) {
            $memcachedActivity = $this->initMemcachedActivities($userId);
        }

        $activitiesHash = $this->getActivitiesHash($activities);
        if($memcachedActivity['hash'] !== $activitiesHash) {
            $this->memcached->set($activitiesKey, [
                'hash' => $activitiesHash,
                'visible' => false,
                self::ACTIVITY_MEMCACHED_TIMEOUT
            ]);
        }
    }

    public function setVisibleActivities(int $userId)
    {
        $activitiesKey = $this->getMemcachedKey($userId);
        $memcachedActivity = $this->memcached->get($activitiesKey);
        if(!$memcachedActivity) {
            $memcachedActivity = $this->initMemcachedActivities($userId);
        }

        $activitiesHash = $memcachedActivity['hash'];
        $this->memcached->set($activitiesKey, [
            'hash' => $activitiesHash,
            'visible' => true,
            self::ACTIVITY_MEMCACHED_TIMEOUT
        ]);
    }

    public function isVisibleActivities(int $userId)
    {
        $this->getActivities($userId);

        $activitiesKey = $this->getMemcachedKey($userId);
        $memcachedActivity = $this->memcached->get($activitiesKey);
        if(!$memcachedActivity) {
            $this->initMemcachedActivities($userId);
            return true;
        }

        return $memcachedActivity['visible'];
    }

    private function getMemcachedKey(int $userId)
    {
        return self::ACTIVITY_MEMCACHED_KEY.$userId;
    }

    private function getActivitiesHash(array $activities = null)
    {
        $createdAt = (new \DateTime("2020-01-01 00:00:00"))->format('Y-m-d h:i:s');
        $activities = $activities ?: [['id' => 0, 'created_at' => $createdAt]];
        $activitiesHash = "0_{$createdAt}";
        if(isset($activities[0])) {
            $activitiesHash = "{$activities[0]['id']}_{$activities[0]['created_at']}";
        }

        return md5($activitiesHash);
    }

    public function initMemcachedActivities(int $userId)
    {
        $activities = $this->apiExchanger->getUserActivities($userId);
        $activitiesHash = $this->getActivitiesHash($activities);

        $this->memcached->set($this->getMemcachedKey($userId), [
            'hash' => $activitiesHash,
            'visible' => true,
            self::ACTIVITY_MEMCACHED_TIMEOUT
        ]);
        return $this->memcached->get($this->getMemcachedKey($userId));
    }

    public function removeActivity(int $activityId, int $userId)
    {
        $result = $this->apiExchanger->removeActivity($activityId);
        $this->getActivities($userId, true);

        return;
    }

    public function updateActivityFromBack(int $userId, array $activities = [])
    {
        $activitiesHash = $this->getActivitiesHash($activities);
        $this->memcached->set($this->getMemcachedKey($userId), [
            'hash' => $activitiesHash,
            'visible' => true,
            self::ACTIVITY_MEMCACHED_TIMEOUT
        ]);

        $this->apiExchanger->clearCache(Path::GET_USER_ACTIVITIES, ['userId' => $userId]);
    }

    public function getActivityMessage(Activity $activity)
    {
        $message = '';

        switch ($activity->getType()) {
            case Type::TYPE_REQUEST_FRIEND:
                $message = 'activity_user_want_friend';
                break;
            case Type::TYPE_IS_FRIEND:
                $message = 'activity_user_become_friend';
                break;
            case Type::TYPE_NEW_COMMENT:
                $message = 'activity_user_commented_photо';
                break;
            case Type::TYPE_NEW_VIDEO_COMMENT:
                $message = 'activity_user_commented_video';
                break;
            case Type::TYPE_VISIT:
                $message = 'activity_user_visited';
                break;
            case Type::TYPE_PHOTO_LIKE:
                $message = 'activity_user_liked_photo';
                break;
            case Type::TYPE_VIDEO_LIKE:
                $message = 'activity_user_liked_video';
                break;
            case Type::TYPE_PHOTO_MODERATION:
                $message = 'activity_photo_moderation';
                break;
            case Type::TYPE_PHOTO_APPROVED:
                $message = 'activity_photo_activated';
                break;
            case Type::TYPE_PHOTO_REJECTED:
                $message = 'activity_photo_deactivated';
                break;
            case Type::TYPE_VIDEO_MODERATION:
                $message = 'activity_video_moderation';
                break;
            case Type::TYPE_VIDEO_APPROVED:
                $message = 'activity_video_activated';
                break;
            case Type::TYPE_VIDEO_REJECTED:
                $message = 'activity_video_deactivated';
                break;
            case Type::TYPE_INVITE_CHAT:
                $message = 'activity_chat_invite';
                break;
            case Type::TYPE_GROUP_USER_REQUEST_JOIN:
                $message = 'groups_user_want_to_join';
                break;
            case Type::TYPE_GROUP_USER_REQUEST_INVITATION:
                $message = 'groups_user_offer_to_join';
                break;
            case Type::TYPE_NEW_ACTIVITY_IN_GROUP:
                $message = 'activity_user_group_notification';
                break;
            case Type::TYPE_ACTIVITY_USER_GROUP_JOIN:
                $message = 'activity_user_group_join';
                break;
            case Type::TYPE_GROUP_USER_JOINED:
                $message = 'groups_user_joined';
                break;
            case Type::TYPE_PHOTO_REAL:
                $message = 'activity_real';
                break;
            case Type::TYPE_PHOTO_REAL_FAILED:
                $message = 'activity_real_failed';
                break;
            case Type::TYPE_NEW_PUBLICATIONS_IN_GROUP:
                $message = 'activity_user_group_posts';
                break;
            case Type::TYPE_NEW_PUBLICATIONS_IN_GROUP_FOR_MEMBER:
                $message = 'activity_user_group_posts';
                break;
            case Type::TYPE_GROUP_AVATAR_STATUS_NEW:
                $message = 'activity_photo_moderation';
                break;
            case Type::TYPE_GROUP_AVATAR_STATUS_APPROVED:
                $message = 'activity_photo_activated';
                break;
            case Type::TYPE_GROUP_AVATAR_STATUS_REJECTED:
                $message = 'activity_photo_deactivated';
                break;
            case Type::TYPE_ADD_TO_FAVOURITES:
                $message = 'activity_added_to_favorites';
                break;
            case Type::TYPE_PRESENT_RECEIVED:
                $message = 'activity_gifts_present';
                break;
            case Type::TYPE_PRESENT_RECEIVED_INCOGNITO:
                $message = 'activity_gifts_present_anonym';
                break;
        }

        return $message;
    }

    public function getGroupActivity(Group $group, ?User $user, bool $force = false)
    {
        $groupActivities = [];

        if($group->getUser() && $user && $group->getUser()->getId() === $user->getId()) {
            $groupActivities = $this->apiExchanger->getGroupActivities($group->getId(), $force);
        }

        $activities = [];
        foreach ($groupActivities as $activityRaw) {
            if(is_array($activityRaw)) {
                $activities[] = (new Activity())->fromArray($activityRaw);
            }
        }

        return $activities;
    }

    public function getUserGroupInviteActivities(int $userId, $force = false): ?array
    {
        $activitiesRaw = $this->getGroupInviteActivities($userId, $force);
        if(!$activitiesRaw) {
            return null;
        }

        $activities = [];
        foreach ($activitiesRaw as $activityRaw) {
            if(is_array($activityRaw)) {
                $activities[] = (new Activity())->fromArray($activityRaw);
            }
        }

        return $activities;
    }

    private function getGroupInviteActivities(int $userId, $force = false)
    {
        $activities = $this->apiExchanger->getUserGroupInviteActivities($userId, $force);
        if(!$activities) {
            return null;
        }
        $this->checkActivitiesInMemcached($userId, $activities);

        return $activities;
    }
}
