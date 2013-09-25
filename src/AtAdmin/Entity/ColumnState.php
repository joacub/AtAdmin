<?php
namespace AtAdmin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Zend\Form\Annotation as Form;

/**
 *
 * @author Johan
 *         @ORM\Entity
 *         @ORM\Table(name="columns_states")
 */
class ColumnState
{

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="ZfJoacubUser\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="user_id", nullable=true)
     */
    protected $user;

    /**
     * @ORM\Column(type="string", name="`column`")
     */
    protected $column;

    /**
     * @ORM\Column(type="integer", name="`position`")
     */
    protected $position;

    /**
     * @ORM\Column(type="boolean", name="`status`")
     */
    protected $status;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    public function getColumn()
    {
        return $this->column;
    }

    public function setColumn($column)
    {
        $this->column = $column;
        return $this;
    }

    public function getPosition()
    {
        return $this->position;
    }

    public function setPosition($position)
    {
        $this->position = $position;
        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }
}