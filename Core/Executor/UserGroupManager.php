<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Kaliop\eZMigrationBundle\Core\Matcher\UserGroupMatcher;
use Kaliop\eZMigrationBundle\API\Collection\UserGroupCollection;

/**
 * Handles user-group migrations.
 */
class UserGroupManager extends RepositoryExecutor
{
    protected $supportedStepTypes = array('user_group');

    protected $userGroupMatcher;

    public function __construct(UserGroupMatcher $userGroupMatcher)
    {
        $this->userGroupMatcher = $userGroupMatcher;
    }

    /**
     * Method to handle the create operation of the migration instructions
     */
    protected function create()
    {
        $userService = $this->repository->getUserService();

        $parentGroupId = $this->dsl['parent_group_id'];
        if ($this->referenceResolver->isReference($parentGroupId)) {
            $parentGroupId = $this->referenceResolver->getReferenceValue($parentGroupId);
        }

        $parentGroup = $userService->loadUserGroup($parentGroupId);

        $contentType = $this->repository->getContentTypeService()->loadContentTypeByIdentifier("user_group");

        $userGroupCreateStruct = $userService->newUserGroupCreateStruct($this->getLanguageCode(), $contentType);
        $userGroupCreateStruct->setField('name', $this->dsl['name']);

        if (array_key_exists('description', $this->dsl)) {
            $userGroupCreateStruct->setField('description', $this->dsl['description']);
        }

        $userGroup = $userService->createUserGroup($userGroupCreateStruct, $parentGroup);

        if (array_key_exists('roles', $this->dsl)) {
            $roleService = $this->repository->getRoleService();
            foreach ($this->dsl['roles'] as $roleId) {
                if (is_int($roleId)) {
                    $role = $roleService->loadRole($roleId);
                } else {
                    // Assume it is an identifier if it is not an int
                    $role = $roleService->loadRoleByIdentifier($roleId);
                }
                $roleService->assignRoleToUserGroup($role, $userGroup);
            }
        }

        $this->setReferences($userGroup);

        return $userGroup;
    }

    /**
     * Method to handle the update operation of the migration instructions
     *
     * @throws \Exception When the ID of the user group is missing from the migration definition.
     */
    protected function update()
    {
        $userGroupCollection = $this->matchUserGroups('delete');

        if (count($userGroupCollection) > 1 && array_key_exists('references', $this->dsl)) {
            throw new \Exception("Can not execute Group update because multiple groups match, and a references section is specified in the dsl. References can be set when only 1 group matches");
        }

        $userService = $this->repository->getUserService();
        $contentService = $this->repository->getContentService();

        foreach($userGroupCollection as $key => $userGroup) {

            /** @var $updateStruct \eZ\Publish\API\Repository\Values\User\UserGroupUpdateStruct */
            $updateStruct = $userService->newUserGroupUpdateStruct();

            /** @var $contentUpdateStruct \eZ\Publish\API\Repository\Values\Content\ContentUpdateStruct */
            $contentUpdateStruct = $contentService->newContentUpdateStruct();

            if (isset($this->dsl['name'])) {
                $contentUpdateStruct->setField('name', $this->dsl['name']);
            }

            if (isset($this->dsl['description'])) {
                $contentUpdateStruct->setField('description', $this->dsl['description']);
            }

            $updateStruct->contentUpdateStruct = $contentUpdateStruct;

            $userGroup = $userService->updateUserGroup($userGroup, $updateStruct);

            if (array_key_exists('parent_group_id', $this->dsl)) {
                $parentGroupId = $this->dsl['parent_group_id'];
                if ($this->referenceResolver->isReference($parentGroupId)) {
                    $parentGroupId = $this->referenceResolver->getReferenceValue($parentGroupId);
                }

                $newParentGroup = $userService->loadUserGroup($parentGroupId);

                // Move group to new parent
                $userService->moveUserGroup($userGroup, $newParentGroup);
            }

            $userGroupCollection[$key] = $userGroup;
        }

        $this->setReferences($userGroupCollection);

        return $userGroupCollection;
    }

    /**
     * Method to handle the delete operation of the migration instructions
     *
     * @throws \Exception When there are no groups specified for deletion.
     */
    protected function delete()
    {
        $userGroupCollection = $this->matchUserGroups('delete');

        $userService = $this->repository->getUserService();

        foreach($userGroupCollection as $userGroup) {
            $userService->deleteUserGroup($userGroup);
        }

        return $userGroupCollection;
    }

    /**
     * @param string $action
     * @return RoleCollection
     * @throws \Exception
     */
    protected function matchUserGroups($action)
    {
        if (!isset($this->dsl['id']) && !isset($this->dsl['group']) && !isset($this->dsl['match'])) {
            throw new \Exception("The id  of a group or a match condition is required to $action it.");
        }

        // Backwards compat
        if (!isset($this->dsl['match'])) {
            if (isset($this->dsl['id'])) {
                $this->dsl['match']['id'] = $this->dsl['id'];
            }
            if (isset($this->dsl['group'])) {
                $this->dsl['match']['email'] = $this->dsl['group'];
            }
        }

        $match = $this->dsl['match'];

        // convert the references passed in the match
        foreach ($match as $condition => $values) {
            if (is_array($values)) {
                foreach ($values as $position => $value) {
                    if ($this->referenceResolver->isReference($value)) {
                        $match[$condition][$position] = $this->referenceResolver->getReferenceValue($value);
                    }
                }
            } else {
                if ($this->referenceResolver->isReference($values)) {
                    $match[$condition] = $this->referenceResolver->getReferenceValue($values);
                }
            }
        }

        return $this->userGroupMatcher->match($match);
    }

    /**
     * Set references defined in the DSL for use in another step during the migrations.
     *
     * @throws \InvalidArgumentException When trying to set a reference to an unsupported attribute
     * @param \eZ\Publish\API\Repository\Values\User\UserGroup|UserGroupCollection $userGroup
     * @return boolean
     */
    protected function setReferences($userGroup)
    {
        if (!array_key_exists('references', $this->dsl)) {
            return false;
        }

        if ($userGroup instanceof UserGroupCollection) {
            if (count($userGroup) > 1) {
                throw new \InvalidArgumentException('UserGroup Manager does not support setting references for creating/updating of multiple groups');
            }
            $userGroup = reset($userGroup);
        }

        foreach ($this->dsl['references'] as $reference) {

            switch ($reference['attribute']) {
                case 'object_id':
                case 'content_id':
                case 'id':
                    $value = $userGroup->id;
                    break;
                default:
                    throw new \InvalidArgumentException('User Group Manager does not support setting references for attribute ' . $reference['attribute']);
            }

            $this->referenceResolver->addReference($reference['identifier'], $value);
        }

        return true;
    }
}
